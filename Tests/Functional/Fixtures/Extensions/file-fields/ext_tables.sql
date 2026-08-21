#
# Schema of the file field this fixture adds to "tt_content".
#
# It exists for TYPO3 v12 alone. On v13 the column is derived from the TCA by
# \TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema, whose
# enrichSingleTableFieldsFromTcaColumns() gives a "file" field an unsigned
# integer holding the number of references. TYPO3 v12 has no such method: its
# enrichSingleTableFields() derives the "ctrl" fields only, so a column an
# extension adds to an existing table through TCA alone never reaches the
# database there - and a file reference written against it would count up a
# column that does not exist.
#
# The type is the one v13 derives, so that the schema is the same on both core
# versions; it is also the type core itself declares for "tt_content.image".
#
# @todo Remove this file once TYPO3 v12 support is dropped.
#

#
# Table structure for table 'tt_content'
#
CREATE TABLE tt_content (
	tx_testsfilefields_media int(11) unsigned DEFAULT '0' NOT NULL
);
