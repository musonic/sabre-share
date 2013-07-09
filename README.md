#sabre-share
===========

Plugin for for [SabreDAV](https://github.com/evert/SabreDAV) a WebDAV framework for PHP.
This plugin implements calendar sharing as outlined here https://trac.calendarserver.org/browser/CalendarServer/trunk/doc/Extensions/caldav-sharing.txt

##Requirements
------------

This requires SabreDAV version 1.8 or later.

##Installation
------------

To install this plugin, make sure you have SabreDAV installed using the composer installation instructions.
To add this plugin, just add the following line to your composer file in the `requires` section:

```
"musonic/sabre-share" : "dev-master"
```

After adding that, you can just run `composer update` to complete the installation.

##Setup
-----

Update your server.php file (or whatever you have named it)

```php
$calendarBackend = new \SabreShare\CalDAV\Backend\SabreSharePDO($pdo);
$calDavSharingPlugin = new \Sabre\CalDAV\SharingPlugin();
$server->addPlugin($calDavSharingPlugin);  
```  

You must also create a table in your database to store the shares. The default table name is "calendarShares" but this can be manually set by adding the following line to your server.php file:

```php
$calendarBackend->setCalendarSharesTableName('myTableName');
```

The table MUST have the following columns:
"calendarId",
"member",
"status",
"readonly",
"summary",
"displayName",
"color"

Example sql:

```SQL
CREATE TABLE `calendarShares` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `calendarId` int(11) NOT NULL,
  `member` int(11) NOT NULL,
  `status` int(1) NOT NULL,
  `readonly` tinyint(1) NOT NULL DEFAULT '0',
  `summary` varchar(150) DEFAULT NULL,
  `displayName` varchar(100) DEFAULT NULL,
  `color` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8;
```

##Usage
-----

Please note that this plugin does not come with a GUI. If you have set it up correctly you should now be able to share calendars with other users identified by their
registered email address.
Please also note that there is not as yet any support for the notifications that usually go along with calendar sharing. 
This is currently being implemented and an update will be available in due course.
