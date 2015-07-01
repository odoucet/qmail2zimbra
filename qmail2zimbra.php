<?php

/**************
* Qmail to Zimbra config converter
*
* @version 1.0
* @author github.com/odoucet
* Based on work by Jaros.aw Czarniak on 26.10.2008
*          modifications by brainity.com on 31.7.2009
*          imapsync part by Julien Dupuis
*
* We read vpasswd config and .qmail files and create Zimbra command line accordingly.
* We generate three files per domain, so that you can migrate domains one by one :
* - domain.com.creation ==> accounts creation (and mailing lists)
* - domain.com.alter    ==> account modifications (alias, redirections, etc.). Must be executed after creation
* - domain.com.imapsync.sh ==> Imapsync script
* - domain.com.setpass  ==> To be executed _after_ importing data : will change passwords and set the old one.
*/

error_reporting(E_ALL);

$config = parse_ini_file(__DIR__.'/config.ini');
if (!$config) {
    echo "Please add config.ini file (based on config.ini.sample)\n";
    exit(1);
}

$stats = array(
    'domains'  => 0,
    'accounts' => 0,
    'mailings' => 0,
    'aliases'  => 0,
    'redirections' => 0,
);

$startTime = microtime(true);

// Prepare domain aliases, to be added later
$domainAliasesArray = array();

$data = file($config['qmail_users_assign']);
foreach ($data as $line) {
    // Syntax: "+dom.com-:dom2.com:89:89:/home/path/to/domain:-::"
    $line = explode(':', $line);
    if (count($line) < 4) {
        continue;
    }

    $line[0] = substr($line[0], 1, -1);

    if ($line[1] == $line[0]) {
        continue;
    }

    $domainAliasesArray[$line[1]][] = $line[0];
}


foreach ($config['vpopmaildirs'] as $vpopMailDirectory) {
    if (!is_dir($vpopMailDirectory)) {
        echo "ERROR: 'vpopmaildirs' config is wrong : '".$vpopMailDirectory.
        ' is not a directory (and should be)'."\n";
        continue;
    }

    // List folders
    $d = dir($vpopMailDirectory);
    while (false !== ($domainName = $d->read())) {
        if ($domainName == '.' || $domainName == '..') {
            continue;
        }

        if (!is_dir($vpopMailDirectory.'/'.$domainName)) {
            continue;
        }

        if (preg_match('@^[0-9]{1,}$@', $domainName) === 1) {
            // subfolder of vpopmail, that also contains domain. Do not parse it
            continue;
        }

        $creationFile = __DIR__.'/results/'.$domainName.'.creation';
        $alterFile = __DIR__.'/results/'.$domainName.'.alter';
        $passwordFile = __DIR__.'/results/'.$domainName.'.password';
        $imapSyncFile = __DIR__.'/results/'.$domainName.'.imapsync';

        if (!file_exists(__DIR__.'/results/')) {
            mkdir(__DIR__.'/results/', 0600);
        }

        // Empty files
        @unlink($creationFile);
        @unlink($alterFile);
        @unlink($passwordFile);
        @unlink($imapSyncFile);

        // Header IMAPSync file
        file_put_contents($imapSyncFile, "#!/bin/bash\nCMD=\"".$config['imapsyncbin']."\"\n\n");

        //printf("%s\n", $domainName);

        file_put_contents($creationFile, ' createDomain '.$domainName."\n", FILE_APPEND);
        // if ZimbraPublicServiceHostname is set :
        if ($config['zimbra_public_service_hostname'] != '') {
            file_put_contents(
                $creationFile,
                ' modifyDomain '.$domainName.' zimbraPublicServiceHostname '.
                $config['zimbra_public_service_hostname']."\n",
                FILE_APPEND
            );
        }
        $stats['domains']++;

        // Add aliases
        if (isset($domainAliasesArray[$domainName])) {
            foreach ($domainAliasesArray[$domainName] as $dom) {
                file_put_contents(
                    $creationFile,
                    ' createAliasDomain '.$dom.' '.$domainName.
                    ' zimbraMailCatchAllForwardingAddress  @'.$domainName."\n",
                    FILE_APPEND
                );
            }
        }

        // Read vpasswd file
        $vpasswdLines = file(
            $vpopMailDirectory.'/'.$domainName.'/vpasswd',
            FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES
        );

        // keep a list
        $createdAccounts = array();

        foreach ($vpasswdLines as $account) {
            list($user, $passwd, , , $name, , $quota) = explode(':', $account);

            //printf("User: %s '%s'\n", $user, $name);

            file_put_contents(
                $creationFile,
                ' createAccount '.$user.'@'.$domainName.' "'.
                $config['temporarypassword'].'" displayName "'.
                convert_name($name).'" givenName "'.convert_name($name).'"'."\n",
                FILE_APPEND
            );
            $stats['accounts']++;

            $createdAccounts[] = $user;

            file_put_contents(
                $passwordFile,
                ' modifyAccount '.$user.'@'.$domainName.' userPassword \'{crypt}'.$passwd.'\''."\n",
                FILE_APPEND
            );

            if ($quota != 0) {
                // transform
                if (substr($quota, -1, 1) == 'S') {
                    $quota = (int) substr($quota, 0, -1);
                } elseif (substr($quota, -2, 2) == 'MB') {
                    $quota = (int) substr($quota, 0, -2) * 1024*1024;
                } elseif (substr($quota, -6) == ',1000C') {
                    $quota = (int) substr($quota, 0, -7);
                } else {
                    echo "Case not handled of quota : ".$quota."\n";
                }

                file_put_contents(
                    $alterFile,
                    ' modifyAccount '.$user.'@'.$domainName.' zimbraMailQuota "'.$quota.'"'."\n",
                    FILE_APPEND
                );

            }

            // IMAPSync script
            file_put_contents(
                $imapSyncFile,
                '$CMD '.$config['imapsync_flags'].' --host1 '.$config['imapsync_source'].' --port1 '.
                $config['imapsync_source_port'].' --user1 '.$user.'@'.$domainName.' --authuser1 '.
                $config['imapsync_source_login'].' --password1 "'.$config['imapsync_bypass_password'].
                '" --host2 '.$config['imapsync_dest'].' --user2 '.$user.'@'.$domainName.' '.
                ' --password2 "'.$config['temporarypassword'].'" --tls2 '."\n",
                FILE_APPEND
            );
        } // end vpasswd file parsing

        // Mailing list
        $existingMailingLists = array();

        $mailingLists = glob($vpopMailDirectory.'/'.$domainName.'/*/mailinglist');
        if (count($mailingLists) > 0) {
            foreach ($mailingLists as $mailingList) {
                // mailing list address :
                $mailingAddress = substr(
                    $mailingList,
                    strlen($vpopMailDirectory.'/'.$domainName.'/'),
                    -strlen('/mailinglist')
                );
                $existingMailingLists[] = $mailingAddress;

                file_put_contents(
                    $creationFile,
                    ' createDistributionList '.$mailingAddress.'@'.$domainName."\n",
                    FILE_APPEND
                );
                $stats['mailings']++;

                // Add members
                if (!file_exists($config['ezmlmlist_bin'])) {
                    echo "ERROR: ezmlmlist_bin needed and binary does not exist. cannot extract ML members\n";
                } else {
                    $members = explode(
                        "\n",
                        shell_exec(
                            $config['ezmlmlist_bin'].' '.$vpopMailDirectory.'/'.
                            $domainName.'/'.$mailingAddress
                        )
                    );
                    foreach ($members as $member) {
                        if (trim($member) == '') {
                            continue;
                        }
                        file_put_contents(
                            $creationFile,
                            ' addDistributionListMember '.$mailingAddress.'@'.$domainName.' '.$member."\n",
                            FILE_APPEND
                        );
                    }
                }
            }
        }

        // Handle .qmail files : responder, mailing lists, redirects, etc.
        # Can contain : (http://qmail.omnis.ch/man/man5/dot-qmail.html)
        #    - ezmlm instructions    meaning it's a distributionList
        #    - &email@domain.com     meaning it's a simple redirect (local or distant)
        #    - | /home/vpopmail/bin/vdelivermail '' delete                    => delete
        #    - | /home/vpopmail/bin/vdelivermail '' bounce-no-mailbox         => bounce
        #             => default behaviour will be BOUNCE for both

        #    - | /home/vpopmail/bin/vdelivermail '' postmaster@retina-as-eden.com => redirect
        #    - |/usr/local/bin/autorespond 10000 5 (pathTomessage) (pathToMaildir)

        # Zimbra properties :
        # zimbraPrefMailLocalDeliveryDisabled == don't keep a local copy
        # zimbraPrefMailForwardingAddress
        # zimbraPrefOutOfOfficeReply (message) zimbraPrefOutOfOfficeReplyEnabled (flag)
        # zimbraDomainAggregateQuota     Quota max for each domain

        # Catch All : modifyAccount user@domain.com zimbraMailCatchAllAddress @domain.com


        # Check special file .qmail-default if catchall
        if (file_exists($vpopMailDirectory.'/'.$domainName.'/.qmail-default')) {
            $content = trim(file_get_contents($vpopMailDirectory.'/'.$domainName.'/.qmail-default'));

            $redirectEmail = null;
            if (strpos($content, 'vdelivermail') !== false) {
                // many cases :
                // email
                if (preg_match('/ ([^\s]{1,}@[^\s]{1,})/', $content, $email)) {
                    $redirectEmail = $email[1];
                } elseif (preg_match('@ '.$vpopMailDirectory.'/'.$domainName.'/([^\s]{1,})@', $content, $email)) {
                    $redirectEmail = $email[1].'@'.$domainName;
                }

                if ($redirectEmail !== null) {
                    file_put_contents(
                        $alterFile,
                        ' modifyAccount '.$redirectEmail.' zimbraMailCatchAllAddress @'.$domainName."\n",
                        FILE_APPEND
                    );
                }
            }
        } //end .qmail-default

        // Read all .qmail files now
        $qmailFiles = glob($vpopMailDirectory.'/'.$domainName.'/.qmail-*');

        // qmail files can be in userdirectory too ...
        $qmailFiles2 = glob($vpopMailDirectory.'/'.$domainName.'/*/.qmail');

        $qmailFiles = array_merge($qmailFiles, $qmailFiles2);

        // For local alias, we must add them on the same loop
        $localAccountAlias = array();

        // distant redirections, we must add them on the same loop (too)
        $distantRedirections = array();

        foreach ($qmailFiles as $qmailFile) {
            if (strpos($qmailFile, '/.qmail-') !== false) {
                $name = substr($qmailFile, strlen($vpopMailDirectory.'/'.$domainName.'/.qmail-'));
                // $name can contain ':' : it means dot
                $name = str_replace(':', '.', $name);

            } else {
                $name = strstr(substr($qmailFile, strlen($vpopMailDirectory.'/'.$domainName.'/')), '/', true);

            }
            

            if ($name == 'default') {
                // already done
                continue;
            }

            if (strpos(basename($qmailFile), '-owner') !== false) {
                // @todo : double check this is a mlm, search in $existingMailingLists
                continue;
            }

            $qmailFileContent = file($qmailFile);

            foreach ($qmailFileContent as $line) {
                if (trim($line) == '') {
                    // empty line
                    continue;
                }

                if (strpos($line, 'ezmlm') !== false) {
                    // Mailing list, already done, skip whole file
                    continue 2;
                }

                // Redirect to maildir
                if (strpos($line, '/Maildir')) {
                    // parse domain directory to see whether it is distant or local
                    if (strpos($line, '/'.$domainName.'/') !== false) {
                        // local
                        $tmp = explode('/', trim(substr($line, strpos($line, '/'.$domainName.'/'), -(strlen('/Maildir/'))), '/'));
                        $tmp = $tmp[1].'@'.$tmp[0];
                        $localAccountAlias[ $tmp ][] = $name.'@'.$domainName;

                    } else {
                        // distant
                        $tmp = substr($line, strlen($vpopMailDirectory.'/'), -strlen('/Maildir/'));
                        echo 'distant: ';
                        var_dump($tmp);
                    }
                    // we can have multiple lines after, so no "continue" here
                }

                if (strpos($line, '/autorespond ') !== false) {
                    // Responder

                    # if account does not exist, create it with zimbraPrefMailLocalDeliveryDisabled TRUE
                    if (!in_array($name, $createdAccounts)) {
                        file_put_contents(
                            $creationFile,
                            ' createAccount '.$name.'@'.$domainName.' "'.
                            $config['temporarypassword'].'" displayName "'.
                            convert_name($name).' (disabled)" givenName "'.convert_name($name).' (disabled)"'."\n",
                            FILE_APPEND
                        );
                        $stats['accounts']++;
                        $createdAccounts[] = $name;

                        file_put_contents(
                            $alterFile,
                            ' modifyAccount '.$name.'@'.$domainName.' zimbraPrefMailLocalDeliveryDisabled TRUE'."\n",
                            FILE_APPEND
                        );
                    }

                    $args = preg_split('@\s+@', str_replace('| /', '|/', $line));

                    if (!file_exists($args[3])) {
                        echo 'Responder for '.$name.'@'.$domainName.' refers to a non-existing file : '.$args[3]."\n";
                        var_dump($args);
                    } else {
                        $msgContent = convert_name(file_get_contents($args[3]));
                        file_put_contents(
                            $alterFile,
                            ' modifyAccount '.$name.'@'.$domainName.' zimbraPrefOutOfOfficeReply "'.
                            convert_name(str_replace("\n", '\n', substr($msgContent, strpos($msgContent, "\n", strpos($msgContent, "\n")+1)+2, 8000))).'"'."\n",
                            FILE_APPEND
                        );
                    }
                    continue;
                }

                // Redirect, syntax #1
                if (strpos($line, '&') === 0) {
                    $line = substr($line, 1);
                }

                if (strpos($line, '@') !== false) {
                    // local or distant ?
                    if (strpos($line, '@'.$domainName) === false || count($qmailFileContent) > 1) {
                        // distant - we should create the email and set some Zimbra values
                        $distantRedirections[$name.'@'.$domainName][] = trim($line);

                    } else {
                        // local - check if account exists or else this is a misconfig so print warning but add nothing
                        $tmp = substr(trim($line), 0, strpos(trim($line), '@'));
                        if (in_array($tmp, $createdAccounts)) {
                            $localAccountAlias[trim($line)][] =  $name.'@'.$domainName;
                        } else {
                            echo 'WARNING: '.$name.'@'.$domainName.' redirects to '.trim($line).' but this local account does not exist'."\n";
                        }
                    }
                }

            } // end foreach lines of .qmail file
        } // end .qmail-* files

        // create accounts needed
        foreach ($distantRedirections as $src => $dstArray) {
            $accountName = substr($src, 0, strpos($src, '@'));
            if (!in_array($accountName, $createdAccounts)) {
                file_put_contents(
                    $creationFile,
                    ' createAccount '.$src.' "'.
                    $config['temporarypassword'].'" displayName "'.
                    convert_name($accountName).' (disabled)" givenName "'.convert_name($accountName).' (disabled)"'."\n",
                    FILE_APPEND
                );
                $stats['accounts']++;
                $createdAccounts[] = $accountName;
            }

            // if we also have local accounts, we must add them here because in next loop, we will have
            // an error stating the email account already exists.
            foreach ($localAccountAlias as $src2 => $dstArray2) {
                foreach ($dstArray2 as $id => $email) {
                    if ($email === $src) {
                        $dstArray[] = $src2;
                        unset($localAccountAlias[$src2][$id]);
                    }
                }
            }

            file_put_contents(
                $alterFile,
                ' modifyAccount '.$src.' zimbraPrefMailForwardingAddress "'.
                implode(', ', $dstArray).'"'."\n",
                FILE_APPEND
            );
            $stats['redirections']++;

            file_put_contents(
                $alterFile,
                ' modifyAccount '.$src.' zimbraPrefMailLocalDeliveryDisabled TRUE'."\n",
                FILE_APPEND
            );
        }

        // Zimbra does not handle redirection to alias, so we must transform them if we have.
        foreach ($localAccountAlias as $src => $dstArray) {
            // check if $src is also an alias
            foreach ($localAccountAlias as $src2 => $dstArray2) {
                if (in_array($src, $dstArray2)) {
                    $localAccountAlias[$src2] = $localAccountAlias[$src];
                    unset($localAccountAlias[$src]);
                }
            }
        }

        // Add aliases at the same time
        foreach ($localAccountAlias as $src => $dstArray) {
            foreach ($dstArray as $email) {
                // if $email is an existing account, we cannot use addAccountAlias
                $namePart = substr($email, 0, strpos($email, '@'));

                if (in_array($namePart, $createdAccounts)) {
                    // cannot use alias system, must use forwarding process
                    file_put_contents(
                        $alterFile,
                        ' modifyAccount '.$email.' zimbraPrefMailForwardingAddress "'.
                        implode(', ', $dstArray).'"'."\n",
                        FILE_APPEND
                    );
                    $stats['redirections']++;

                    file_put_contents(
                        $alterFile,
                        ' modifyAccount '.$email.' zimbraPrefMailLocalDeliveryDisabled TRUE'."\n",
                        FILE_APPEND
                    );

                } elseif (in_array(substr($src, 0, strpos($src, '@')), $existingMailingLists)) {
                    // this is a mailing list alias
                    file_put_contents(
                        $alterFile,
                        ' addDistributionListAlias '.$src.' "'.$email.'"'."\n",
                        FILE_APPEND
                    );
                    $stats['aliases']++;

                } else {
                    file_put_contents(
                        $alterFile,
                        ' addAccountAlias '.$src.' "'.$email.'"'."\n",
                        FILE_APPEND
                    );
                    $stats['aliases']++;
                }
            }
        }

    } // end loop dir()->read()
}

echo "Finished in ".round(microtime(true)-$startTime, 2)." seconds.\n";
foreach ($stats as $name => $val) {
    printf("%20s: %4d\n", $name, $val);
}

function convert_name($name)
{
    global $config; // it's bad to use global but hey, nobody's perfect

    return str_replace('"', '\"', iconv('ISO-8859-1', $config['destination_encoding'], $name));
}
