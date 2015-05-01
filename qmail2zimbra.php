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

    if ($line[1] == $line[0]) continue;

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

        // Add aliases
        if (isset($domainAliasesArray[$domainName])) {
            foreach ($domainAliasesArray[$domainName] as $dom) {
                file_put_contents($creationFile, ' createAliasDomain '.$dom.' '.$domainName.
                    ' zimbraMailCatchAllForwardingAddress  @'.$domainName."\n", FILE_APPEND);
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

        foreach ($qmailFiles as $qmailFile) {
            $name = substr($qmailFile, strlen($vpopMailDirectory.'/'.$domainName.'/.qmail-'));

            if ($name == 'default') {
                // already done
                continue;
            }

            if (strpos(basename($qmailFile), '-owner') !== false) {
                // @todo : double check this is a mlm, search in $existingMailingLists
                continue;
            }

            // $name can contain ':' : it means dot
            $name = str_replace(':', '.', $name);

            $qmailFileContent = file($qmailFile);

            // For local alias, we must add them on the same loop
            $localAccountAlias = array();

            // distant redirections, we must add them on the same loop (too)
            $distantRedirections = array();

            foreach ($qmailFileContent as $line) {
                if (trim($line) == '') {
                    // empty line
                    continue;
                }

                if (strpos($line, 'ezmlm') !== false) {
                    // Mailing list, already done, skip whole file
                    continue 2;
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

                        file_put_contents(
                            $alterFile,
                            ' modifyAccount '.$name.'@'.$domainName.' zimbraPrefMailLocalDeliveryDisabled TRUE'."\n",
                            FILE_APPEND
                        ); 
                    }


                    $args = preg_split('@\s+@', $line);

                    $msgContent = convert_name(file_get_contents($args[3]));

                    file_put_contents(
                        $alterFile,
                        ' modifyAccount '.$name.'@'.$domainName.' zimbraPrefOutOfOfficeReply "'.
                        convert_name(str_replace("\n", '\n', substr($msgContent, strpos($msgContent, "\n", strpos($msgContent, "\n")+1)+2, 8000))).'"'."\n",
                        FILE_APPEND
                    );
                    continue;
                }

                // Redirect, syntax #1
                if (strpos($line, '&') === 0) {
                    $line = substr($line, 1);
                }

                if (strpos($line, '@') !== false) {
                    // local or distant ?
                    if (strpos($line, '@'.$domainName) === false) {
                        // distant - we should create the email and set some Zimbra values
                        $distantRedirections[trim($line)][] = $name.'@'.$domainName;

                    } else {
                        // local
                        $localAccountAlias[trim($line)][] =  $name.'@'.$domainName;
                    }
                } else {
                    // Line unknown debug
                    echo "CASE UNKNOWN: ".$qmailFile." \n";
                    var_dump($line);
                }

            } // end foreach lines of .qmail file

            // create accounts needed
            foreach ($distantRedirections as $src => $dstArray) {

                file_put_contents(
                    $creationFile,
                    ' createAccount '.$src.' "'.
                    $config['temporarypassword'].'" displayName "'.
                    convert_name($name).' (disabled)" givenName "'.convert_name($name).' (disabled)"'."\n",
                    FILE_APPEND
                );

                file_put_contents(
                    $alterFile,
                    ' modifyAccount '.$src.' zimbraPrefMailForwardingAddress "'.
                    implode(',', $dstArray).'"'."\n",
                    FILE_APPEND
                );

                file_put_contents(
                    $alterFile,
                    ' modifyAccount '.$src.' zimbraPrefMailLocalDeliveryDisabled TRUE'."\n",
                    FILE_APPEND
                );
            }

            // Add aliases at the same time
            foreach ($localAccountAlias as $src => $dstArray) {
                file_put_contents(
                    $alterFile,
                    ' addAccountAlias '.$src.' "'.implode(',', $dstArray).'"'."\n",
                    FILE_APPEND
                );
            }

        } // end .qmail-* files
    } // end loop dir()->read()
}


function convert_name($name)
{
    global $config; // it's bad to use global but hey, nobody's perfect

    return str_replace('"', '\"', iconv('ISO-8859-1', $config['destination_encoding'], $name));
}

