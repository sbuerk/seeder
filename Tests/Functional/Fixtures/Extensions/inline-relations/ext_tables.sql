#
# Schema of the inline relation this fixture provides.
#
# It exists for TYPO3 v12 alone. On v13 every column below is derived from the
# TCA by \TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema, whose
# enrichSingleTableFieldsFromTcaColumns() turns a "columns" entry into a column
# definition. TYPO3 v12 has no such method: its enrichSingleTableFields()
# derives the "ctrl" fields only - "uid", "pid", "tstamp", "crdate", the
# "delete" and "enablecolumns" fields, the language fields and the "t3ver_*"
# fields - and it skips a table that no ext_tables.sql declares altogether, so
# without this file the two tables of this fixture do not exist on v12 at all
# and the column tying the relation to "tt_content" is missing from it.
#
# Only the columns v13 derives from "columns" are declared, and each with the
# type that derivation produces, so that the schema is the same on both core
# versions. The "ctrl" derived columns are left out deliberately: v12 and v13
# agree on them, and declaring them here would be a second source for a
# definition that already has one.
#
# @todo Remove this file once TYPO3 v12 support is dropped.
#

#
# Table structure for table 'tt_content'
#
CREATE TABLE tt_content (
	tx_testsinlinerelations_items int(11) unsigned DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_testsinlinerelations_item'
#
CREATE TABLE tx_testsinlinerelations_item (
	title varchar(255) DEFAULT '' NOT NULL,
	image int(11) unsigned DEFAULT '0' NOT NULL,
	links int(11) unsigned DEFAULT '0' NOT NULL,
	parentid int(11) unsigned DEFAULT '0' NOT NULL,
	parenttable varchar(255) DEFAULT '' NOT NULL,
	sorting_foreign int(11) DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_testsinlinerelations_link'
#
CREATE TABLE tx_testsinlinerelations_link (
	title varchar(255) DEFAULT '' NOT NULL,
	parentid int(11) unsigned DEFAULT '0' NOT NULL,
	parenttable varchar(255) DEFAULT '' NOT NULL,
	sorting_foreign int(11) DEFAULT '0' NOT NULL
);
