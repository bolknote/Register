<?php

declare(strict_types = 1);

// Language definitions used in install.php
$lang_install = array(

// Install Form
'Install S2'				=>	'Install Register %s',
'Part 0'					=>	'Change installer language',
'Choose language help'		=>	'You can change the language of this install script if you find it easier to follow the instructions in your own language. Just choose your language from the list of installed ones below.',
'Installer language'		=>	'Installer language',
'Choose language'			=>	'Change language',
'Part1'						=>	'Database setup',
'Part1 intro'				=>	'Enter the requested information to set up the Register database. Contact your hosting support in case of difficulties.',
'Database error'			=>	'A database error occurred: "%s". Please check your database connection parameters.',
'Database type'				=>	'Database type',
'Database name'				=>	'Database name',
'Database server'			=>	'Database server',
'Database username'			=>	'Database username',
'Database password'			=>	'Database password',
'Table prefix'				=>	'Table prefix',
'Database type N/A'			=>	'(this PHP environment does not have support for it)',
'Database server help'		=>	'The address of the database server.<br />Examples: <em>localhost</em>, <em>mysql1.example.com</em> or <em>208.77.188.166</em>. You can specify a custom port number if your database does not run on the default port (example: <em>localhost:3580</em>). For SQLite support, leave it at “localhost”.',
'Database name help'		=>	'The database where Register will be installed.<br />For SQLite, this is a relative path to the database file. Register will create a missing file. PHP needs write permission for the file and its directory.',
'Database username help'	=>	'For database connection. Ignore for SQLite.',
'Database password help'	=>	'For database connection. Ignore for SQLite.',
'Table prefix help'			=>	'Optional database table prefix, e.g. “test_”.<br />A different prefix allows multiple Register installations in one database.',
'Part2'						=>	'Administrator setup',
'Part2 intro'				=>	'Create the administrator account for Register. You can add more administrators, authors, and moderators in the control panel later.',
'Admin username'			=>	'Username',
'Admin password'			=>	'Password',
'Admin e-mail'				=>	'Administrator email',
'E-mail address help'		=>	'An email associated with your account.<br />If you provide the <em>administrator</em> email, you will receive notifications when visitors post comments. This email will never be published but will be displayed in the control panel to users with granted permissions. You can update this address later.<br />During installation, the value of this field will be assigned as the <em>webmaster</em> email. The webmaster email is used in RSS feeds and as the sender email when mailing comments to subscribers. However, it may be accessible to spammers. The webmaster email can be changed later independently of the emails associated with accounts.',
'Part3'						=>	'Site setup',
'Part3 intro'				=>	'Please enter the requested information about the site.',
'Base URL'					=>	'Base URL',
'Base URL help'				=>	'The blog URL without a trailing slash (for example, <em>https://example.com</em>).<br />Set the correct value or the site will not work properly. The preset value is only Register’s best guess.',
'Default language'			=>	'Site language',
'Default language help'		=>	'If you are going to delete the current language pack (English), you must choose another one before deleting.',
'Start install'				=>	'Start installation', // Label for submit button
'Required'					=>	'(Required)',


// Install errors
'No database support'		=>	'This PHP environment has none of the database drivers Register supports. Enable MySQL, PostgreSQL, or SQLite support before installation.',
'Missing database name'		=>	'You must enter a database name.',
'Username too long'			=>	'Usernames must be no more than 40 characters long.',
'Username too short'		=>	'Usernames must be at least 2 characters long.',
'Password too short'		=>	'Passwords must be at least 12 characters long.',
'Password too long'			=>	'Passwords must be no more than 255 characters long.',
'Password too common'		=>	'Choose a less common password.',
'Password contains username' => 'The password must not contain the administrator login.',
'Invalid email'				=>	'The administrator email address you entered is invalid.',
'Missing base url'			=>	'You must enter a base URL.',
'Invalid base url'			=>	'Enter a valid HTTP or HTTPS base URL without credentials, a query string, or a fragment.',
'No such database type'		=>	'“%s” is not a valid database type.',
'Invalid table prefix'		=>	'The table prefix “%s” contains illegal characters. The prefix may contain the letters a to z, any numbers and the underscore character. They must however not start with a number. Please choose a different prefix.',
'Too long table prefix'		=>	'The table prefix “%s” is too long. The maximum length is 40 characters. Please choose a different prefix.',
'SQLite prefix collision'	=>	'The table prefix “sqlite_” is reserved for use by the SQLite engine. Please choose a different prefix.',
'S2 already installed'		=>	'A table called “%1$susers” already exists in database “%2$s”. Register may already be installed, or another application may be using the required table names.',
'S2 already installed 2'	=>	'To install multiple Register copies in one database, choose a different table prefix.',
'S2 already installed 3'	=>	'To connect this Register installation to the selected database, download config.php with the current parameters and place it alongside the other engine files.',
'Invalid language'			=>	'The language pack you have chosen does not seem to exist or is corrupt. Please recheck and try again.',
'Foreign request'			=>	'This installation request came from another site and was rejected. Reload the installer and try again.',
'Secret file boundary failed' => 'Register cannot safely store API keys on this hosting account. Use the split-root shared-hosting package, make the directory above the document root writable by PHP, or enable and verify the supplied .htaccess rules before retrying.',

// Used in the install
'Site name'					=>	'Register',
'Main Page'					=>	'Main page',
'Welcome title'				=>	'A place for posts',
'Section example'			=>	'Section 1',
'Page example'				=>	'Page 1',
'Welcome text'				=>	'<p>Register is a small, fast engine for a personal blog. Publish posts and permanent pages, organize them with tags, keep an archive, receive comments, and offer posts through RSS without putting a noisy interface in front of readers.</p><h2>What is ready</h2><ul><li>Post drafts and publication;</li><li>comments, moderation, and subscriptions;</li><li>images, tags, favorites, built-in search, and optional modules;</li><li>multiple authors with clear permissions;</li><li>a responsive light and dark reading theme.</li></ul><h2>Start here</h2><ol><li>Open the control panel using the lock in the footer.</li><li>Give the blog its name in Settings.</li><li>Edit or delete this post and publish your first one.</li></ol><p>This is starting content, not a mandatory post. Register is a blog engine, not a universal site builder; its job is to keep writing, publishing, and reading pleasantly direct.</p>',
'Page text'					=>	'Register was installed successfully. Open the control panel using the lock in the footer and configure the blog.',


// Installation completed form
'Success description'		=>	'Congratulations! Register %s was installed successfully.',
'Success welcome'			=>	'Please follow the instructions below to finalize the installation.',
'Final instructions'		=>	'Final instructions',
'No write info 1'			=>	'<strong>Notice!</strong> To finish installation, download config.php with the button below and upload it to the directory containing Register.',
'No write info 2'			=>	'After config.php is uploaded, Register will be fully installed and you may %s.',
'Go to index'				=>	'go to the main page',
'Warning'					=>	'Warning!',
'No cache write'			=>	'<strong>The cache directory is not writable!</strong> Register needs PHP write access to <em>_cache</em>. Make PHP the owner and start with mode 0750; never solve this by granting mode 0777.',
'No pictures write'			=>	'<strong>The picture directory is currently not writable!</strong> Make PHP the owner of <em>_pictures</em>. Start with mode 0755 because uploaded files are public, and use a tighter mode when the web server runs as the same user or group; never grant mode 0777.',
'File upload alert'			=>	'<strong>File uploads appear to be disallowed on this server!</strong> If you want to upload pictures in the control panel, you have to enable the file_uploads configuration setting in PHP.',
'Download config'			=>	'Download config.php', // Label for submit button
'Write info'				=>	'Register is completely installed! Now you may %s.',
);
