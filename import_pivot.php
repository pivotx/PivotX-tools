<?php

// $Id$

// Required version of PivotX
$pivotx_version = '2.0.2';

$this_dir_array = explode(DIRECTORY_SEPARATOR, dirname(__FILE__));

$this_dir_top = array_pop($this_dir_array);

if ($this_dir_top == 'pivotx') {
    // For PivotX 2.x
    require_once('lib.php');
    initializePivotX();
    $ispivotx = true;
} elseif (file_exists('pv_core.php')) {
    // For Pivot 1.x
    require_once('pv_core.php');
    // open the db, make sure it's updated..
    $db = new db();
    $db->generate_index();
    define('PIVOT','Pivot');
    $ispivotx = false;
    $charset = 'iso-8859-1';
    function normalizeCategory($name) {
        return $name;
    }
    function normalizeUser($name) {
        return $name;
    }
} else {    
    die("FATAL ERROR - this import script must be placed in the pivot or pivotx folder.");
}

if ($ispivotx) {
    define('PIVOT','PivotX');
    if (!checkVersion($version, $pivotx_version)) {
        die("FATAL ERROR - this import script is only for PivotX $pivotx_version or newer.");
    }
    $db = $PIVOTX['db']; // So we can use $db in the script for both Pivot and PivotX.
    define('PIVOT','PivotX');
    $charset = 'utf-8';
    function normalizeCategory($name) {
        return safeString($name, true);
    }
    function normalizeUser($name) {
        return safeString($name, true);
    }
}

set_time_limit(0);

$categorylist = array();

function convertFromIso88591($entry) {

    $entry['title'] = utf8_encode($entry['title']);
    $entry['subtitle'] = utf8_encode($entry['subtitle']);
    $entry['introduction'] = utf8_encode($entry['introduction']);
    $entry['body'] = utf8_encode($entry['body']);
    $entry['keywords'] = utf8_encode($entry['keywords']);
    $entry['category'] = array_map('utf8_encode', $entry['category']);
    foreach ($entry['comments'] as $key => $value) {
        $entry['comments'][$key]['comment'] = utf8_encode($entry['comments'][$key]['comment']);
        $entry['comments'][$key]['name'] = utf8_encode($entry['comments'][$key]['name']);
    }
    foreach ($entry['trackbacks'] as $key => $value) {
        $entry['trackbacks'][$key]['name'] = utf8_encode($entry['trackbacks'][$key]['name']);
        $entry['trackbacks'][$key]['title'] = utf8_encode($entry['trackbacks'][$key]['title']);
        $entry['trackbacks'][$key]['excerpt'] = utf8_encode($entry['trackbacks'][$key]['excerpt']);
    }

    return $entry;

}

function warn($text) {
    echo '<p style="color: red; font-weight: bold">'.$text.'</p>';
}

// --- convert: where the actual conversion takes place --- //



/**
 *  Insert the entries into the 
 */
function insert_entry($entry) {
    global $db, $ispivotx;

    $db->set_entry($entry);
    if ($db->save_entry()) {
        if ($ispivotx) {
            $code = $db->entry['uid'];
        } else {
            $code = $db->entry['code'];
        }
        echo "(inserted as entry $code!)<br />";
        return TRUE;
    } else {
        echo "(<b>NOT</b> inserted!)<br />";
        return FALSE;
    }
    flush();


}

// -------
function start_conversion($data, $doit=true) {
    global $ispivotx, $PIVOTX, $db, $content, $categorylist;

    $dir = $data['dir'];
    $fixed_user = $data['user'];
    $fixed_cat = $data['cat'];
    $batch_size = $data['batch_size'];
    $batch_step = $data['batch_step'];
    $batch_start = $batch_size * ($batch_step-1) + 1;
    $batch_end = $batch_size * $batch_step;

    $entries = array();

    // Should we keep entry uids/codes, i.e., potentially overwrite entries?
    if ($doit) {
        $keepuids = false;
        if ($ispivotx && isset($_POST['keepuids'])) {
            $keepuids = true;
            $PIVOTX['config']->set('dont_recreate_default_entries', 1);
        }
    }

    // Finding all entries (as files) in this batch
    $dircount = 0;
    $dir = addTrailingSlash(realpath($dir));
    $done = true;
    if ($dh = opendir($dir)) {
        while (($file = readdir($dh)) !== false) {
            $path = $dir.$file;
            if (is_dir($path) && preg_match('/^standard-\d{5}$/',$file)) {
                $dircount++;
                if ($dircount > $batch_end) {
                    break;
                } else if ($dircount < $batch_start) {
                    continue;
                }
                $done = false;
                $path .= "/";
                $entrydh = opendir($path);
                while (($file = readdir($entrydh)) !== false) {
                    $entrypath = $path.$file;
                    if (is_file($entrypath) && preg_match('/^\d{5}\.php$/',$file)) {
                        array_push($entries,$entrypath);
                    }
                }
                closedir($entrydh);
            }
        }
    }

    if (!$doit) {
        if (!$done) {
            warn("Previewing step $batch_step of the import - you need to " . 
		 "confirm that you want to start the import by checking \"Yes, do it!\".<br />" .
		 "Continue by submitting <a href='#import_form'>the form</a> (at the bottom of the page) again.");
        } else {
            warn("Nothing to preview.");
        }
    } else {
        if (!$done) {
            warn("Executed step $batch_step of the import. Continue by submitting " . 
                "<a href='#import_form'>the form</a> (at the bottom of the page) again.");
        }
    }

    sort($entries);
    foreach($entries as $file) {
        $entry = loadSerialize($file);
        if($ispivotx && !$entry) {
            $entry = liberalUnserialize($file);
        }
        $file = '[...]'.preg_replace('/^.*'.preg_quote($dir,'/').'/','',$file);
      
        // Detecting broken entries 
        $error = false; 
        if (!$entry) {
            $error = "Error: Failed to load entry data from $file - corrupted file?<br />\n";
        } else if (!is_array($entry)) {
            $error = "Error: Malformed entry data in $file - corrupted file?<br />\n";
        }
        if ($error) {
            echo $error;
            continue;
        }

        if (isset($_POST['convert_iso88591'])) { 
            $entry = convertFromIso88591($entry);
        }

        if (!empty($fixed_user)) {
            $entry['user'] = $fixed_user;
        } else {
            $entry['user'] = normalizeUser($entry['user']);
        }
        if (!empty($fixed_cat)) {
            $entry['category']= array($fixed_cat);
        }
        echo "Entry: ($file / " .$entry['code'] . ") " . $entry['title']. " - cats: " .
            implode(", ", $entry['category']) . " - tags: " . $entry['keywords'] . "<br />\n";

        if(is_array($entry['category'])) {

            foreach ($entry['category'] as $key => $cat) {
                // Collect the categories, see if we need to add them later.
                $categorylist[normalizeCategory($cat)] = $cat;

                // Convert the categories to 'safe strings.
                $entry['category'][$key] = normalizeCategory($cat);
            }

        }

        if ($doit) {
            if (!$keepuids) {
                $entry['code']=">";
            }
            insert_entry($entry);
        }
    }
    if ($doit) {
        if ($done) {
            warn("No more steps - import completed.");
            echo "<p><a href='index.php'>Log in to ".PIVOT.".</a>.</p>";
        } else {
            warn("Completed step $batch_step of the import in ".timetaken()." seconds. " . 
                "Continue by submitting the form again.");
            show_form();
        }
    }
}

function show_form() {
    global $ispivotx;
    $self = $_SERVER['PHP_SELF'];
    $dir = isset($_POST['dir']) ? $_POST['dir'] : "old-db" ;
    $batch_size = isset($_POST['batch_size']) ? $_POST['batch_size'] : "10" ;
    $batch_size_readonly = "";
    if (isset($_POST['batch_step'])) {
        $batch_size_readonly = "readonly='readonly' class='readonly'";
        // Move to next batch only if we are going to convert / it's confirmed.
        if (isset($_POST['confirm'])) {
            $batch_step = $_POST['batch_step'] + 1;
        } else {
            $batch_step = $_POST['batch_step'];
        }
    } else {
        $batch_step = 1;
    }
    $confirm = isset($_POST['confirm']) ? 'checked' : '';

    echo "<p>The current path is: ".dirname($self)."</p>";

    echo <<<EOM
    <p>Please give the location of your flatfile database (to be merged) - relative or absolute path.</p>
    <form id='import_form' name='import_form' method='post' action='$self'>
    <table>
    <tr>
        <td valign='top'><b>Directory for (old) database:</b></td>
        <td><input type='text' name='dir' size='30' value='$dir' /><br /><br /></td>
    </tr>
    <tr>
        <td valign='top'><b>Username (optional):</b></td>
        <td><input type='text' name='user' size='30' value='$user' /><br />
        (Which Pivot user should be set as author of the imported entries.<br />
        Only needed if you want to override the entry author in the old database.)<br /></td>
    </tr>
    <tr>
        <td valign='top'><b>Category (optional):</b></td><td><input type='text' name='cat' size='30' value='$cat' /><br />
        (Which Pivot category the imported entries should be stored under.<br />
        Only needed if you want to override the entry category in the old database.)<br /></td>
    </tr>
    <tr>
        <td valign='top'><b>Batch size:</b></td>
        <td><input type='text' name='batch_size' size='30' value='$batch_size' $batch_size_readonly /><br />
        How many entry folders handled in each run. <br /></td>
    </tr>
    <tr>
        <td valign='top'><b>Batch step:</b></td>
        <td><input type='text' name='batch_step' size='30' value='$batch_step' /><br />
        Which batch step we are executing/previewing - automatically incremented. <br /></td>
    </tr>
EOM;
    if ($ispivotx) {
        echo "<tr><td></td><td><input type='checkbox' name='keepuids' value='1' ".
        (isset($_POST['keepuids'])?'checked':'').
        "/> Keep entry codes/uids<br /></td></tr>";
        echo "<tr><td></td><td><input type='checkbox' name='convert_iso88591' value='1' ".
        (isset($_POST['convert_iso88591'])?'checked':'').
        "/> Convert entries from ISO-8859-1 to UTF-8<br /></td></tr>";
    }
    echo <<<EOM
    <tr><td></td><td><input type='checkbox' name='confirm' value='1' $confirm /> <b>Yes, do it!</b> <br /><br /></td></tr>
    <tr><td></td><td><input type='submit' value='Import - step $batch_step' /></td></tr>
    </table>
    </form>
EOM;

}


// -------- Main ----------

?>

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php echo $charset; ?>" />
<style type="text/css">
input.readonly {background-color: ButtonFace;}
</style
</head>
<body>
<h1>Welcome to the quick-and-dirty <?php echo PIVOT; ?> import/merge script</h1>
<p>Use this to import/merge/append <strong>entries</strong> from
<?php 
if ($ispivotx) {
    echo "your old Pivot (or another PivotX) to your new PivotX.</p>";
    if ($PIVOTX['db']->db_type=="sql") {
        echo "<p>Your PivotX is using a MySQL database</p>";
    } else {
        echo "<p>Your PivotX is using a flatfile database</p>";
    }
} else {
    echo 'one Pivot to another Pivot.</p>';
}
?>
<p>NB! Only import from flatfile databases are supported.</p>

<?php

if (count($_POST)>0) {

    // we know there's input..

    if (strlen($_POST['dir'])<1) {

        // no file given
        warn("You need to give the (relative) location of your old Pivot database");
        show_form();

    } else if (!file_exists(realpath($_POST['dir']))) {

        warn("The directory does not exist.. Please verify your input.");
        show_form();

    } else if (!isset($_POST['confirm'])) {

        // not confirmed..
        start_conversion($_POST, false);
        show_form();

    } else {

        // go!
        $categories = array();
        start_conversion($_POST);

        // After the conversion, we'll see if we need to add categories:
        if ($ispivotx) {
            $existingcats = $PIVOTX['categories']->getCategorynames();
            foreach($categorylist as $name=>$display) {
                if(!in_array($name, $existingcats)) {
                    // We have to add this one..
                    $PIVOTX['categories']->setCategory($name, array('name'=>$name, 'display'=>$display));
                }
            }
        } else {    
            $categorylist = array_merge( (explode("|", $Cfg['cats'])), $categorylist);
            $categorylist = array_unique($categorylist);
            $Cfg['cats'] = implode("|", $categorylist);
            SaveSettings();
        }
    }

} else {
    echo '<p style="padding: 4px; border: red solid 1px; width: 50em"><b>Tip:</b> ';
    if ($ispivotx) {
        echo 'If your old Pivot used ISO-8859-1, you should select 
        "Convert entries from ISO-8859-1 to UTF-8" in the form below. It\'s
        also recommended to select "Keep entry codes/uids" <i>potentially overwriting
        existing entries</i>, so you can redirect all your old entry URLs to the new 
        PivotX ones.';
    } else {
        echo 'The imported entries will be appended to this database, 
        in other words, get the highest entry numbers. So if you want
        your oldest entries to keep the low entry numbers, you should import
        your new database into the old one (then just move the db folder back
        to the new install).';
    }
    echo '</p>';
    show_form();
}

?>

</body>
</html>
