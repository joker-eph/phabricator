CREATE TABLE {$NAMESPACE}_phurl.phurl_url (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phid VARBINARY(64) NOT NULL,
  name VARCHAR(255) NOT NULL COLLATE {$COLLATE_TEXT},
  longURL VARCHAR(2047) NOT NULL COLLATE {$COLLATE_TEXT},
  description VARCHAR(2047) NOT NULL COLLATE {$COLLATE_TEXT},
  viewPolicy VARBINARY(64) NOT NULL,
  editPolicy VARBINARY(64) NOT NULL,
  spacePHID varbinary(64) DEFAULT NULL
) ENGINE=InnoDB, COLLATE {$COLLATE_TEXT};
