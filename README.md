# qmail2zimbra
Qmail config converter to Zimbra commands


What it does
------------

* Convert email accounts
* Handle user quota
* Handle mailing lists
* Handle autoresponder
* Handle redirections to external addresses
* Handle redirections to same domain
* Handle catchall to an email

Limitations
-----------

* Account names with accents will be f**ed up. Sorry, won't work. The actual behaviour is to import it with bad
accents, and you should edit them afterwards with Zimbra web interface. Any fix welcome :)


Production-ready
----------------
We successfully migrate ~ 130 domains and 1200+ accounts with this script. But please take care !
This script has no guarantee of work, and all your data may disappear suddenly.

