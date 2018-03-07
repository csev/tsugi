<?php

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Blob\BlobUtil;

require_once("../../config.php");

if ( ! U::isCli() ) die('Must be command line');

if ( trim(shell_exec('whoami')) == 'root' ) {
    echo("Should not be run as root\n\n");
    echo("sudo -H -u www-data _command_   (or similar)\n");
    die();
}

LTIX::getConnection();

$dryrun = ! ( isset($argv[1]) && $argv[1] == 'move');

if ( $dryrun ) {
    echo("This is a dry run, use 'php migrate.php move' to actually move the blobs.\n");
} else {
    echo("This IS NOT A DRILL!\n");
    sleep(5);
    echo("...\n");
    sleep(5);
}

// Check if the destination is blob_blob or dataroot
if ( !isset($CFG->dataroot) || strlen($CFG->dataroot) < 1 ) {
    echo("Migrating from blob_file to blob_blob\n");
    $where = "path IS NULL AND blob_id IS NULL"; // Leave disk blobs alone
} else {
    echo("Migrating from blob_file/blob_blob to $CFG->dataroot\n");
    $where = "path IS NULL";
}

$stmt = $PDOX->query("SELECT file_id, file_sha256, context_id
    FROM {$CFG->dbprefix}blob_file WHERE $where");
$stmt->execute();

$stop = 5;
$checked = 0;
$file_moved = 0;
$blob_moved = 0;
$bytes = 0;

$start = time();
while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
    $checked++;
    if ( $stop > 0 && $checked > $stop ) {
        echo("\nPartial Run: Stopped after $stop blobs\n");
        break;
    }
    $file_id = $row['file_id'];
    $context_id = $row['context_id'];
    if ( $dryrun ) {
        echo("File would migrate file_id=$file_id\n");
        continue;
    }

    $test_key = BlobUtil::isTestKey($context_id);
    $retval = BlobUtil::migrate($file_id, $test_key);
    if ( is_string($retval) ) {
        echo("Could not Migrate file_id=$file_id ".htmlentities($retval)."\n");
        break;
    }
    if ( $retval != true ) {
        echo("File did not migrate file_id=$file_id\n");
    }
}
$delta = time() - $start;

echo("# blobs=$checked file_moved=$file_moved blob_moved=$blob_moved seconds=$delta\n");
