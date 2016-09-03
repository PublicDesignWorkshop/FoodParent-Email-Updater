<?

require 'ExifToolBatch.php';

define('DB_MAIN', 'hostname|username|password|db');
$db = new my_db(DB_MAIN);

if ( 'POST' != $_SERVER['REQUEST_METHOD'] ) {
    header('Allow: POST');
    header('HTTP/1.1 405 Method Not Allowed');
    header('Content-Type: text/plain');
    exit;
}

if (!check_signature($_POST['timestamp'], $_POST['token'], $_POST['signature'])) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain');
    exit;
}

$fruit_type = $_POST['subject'];
$from = $_POST['sender'];
$note = $_POST['stripped-text'];
$rating = explode('@', $_POST['To'])[0];
$tree_id = array();

for ($i = 1; $i <= $_POST['attachment-count']; $i++) {

  $file = $_FILES["attachment-" . $i];
  $data = array('-n',$file['tmp_name']);
  $exif = ExifToolBatch::getInstance('/usr/local/bin/exiftool');
  $exif->add($data);
  $exif_data=$exif->fetchAll();
  $clean_data = json_decode($exif_data[0])[0];

  $direction = $clean_data->{'EXIF'}->{"GPSImgDirection"};
  $latitude = $clean_data->{'EXIF'}->{"GPSLatitude"};
  $longitude = $clean_data->{'EXIF'}->{"GPSLongitude"};
  $img_date = $clean_data->{'EXIF'}->{"DateTimeOriginal"};
  $lat_ref = $clean_data->{'EXIF'}->{"GPSLatitudeRef"};
  $long_ref = $clean_data->{'EXIF'}->{"GPSLongitudeRef"};
  if (preg_match('/^S$/', $lat_ref)) {
    $latitude = -$latitude;
  }
  if (preg_match('/^W$/', $long_ref)) {
    $longitude = -$longitude;
  }

  $long_range_micro = array($longitude - 0.0001, $longitude + 0.0001);
  $long_range_mini = array($longitude - 0.00025, $longitude + 0.00025);
  $lat_range_micro = array($latitude - 0.0001, $latitude + 0.0001);
  $lat_range_mini = array($latitude - 0.00025, $latitude + 0.00025);

  $rows = $db->fetchAll('SELECT id FROM food where name=? or name=?', $fruit_type, pluralize(2, $fruit_type));
  if (sizeof($rows ) > 1) {
    # raise exception - ambiguous fruit name
    header('HTTP/1.1 550 Ambiguous food');
    header('X-CJ-Error: Ambiguous food');
    exit;
  } elseif (sizeof($rows) == 0) {
    header('HTTP/1.1 549 Unknown food');
    header('X-CJ-Error: Unknown food');
    exit;
  } else {
    $fruit_id = $rows[0]->{'id'};
  }

  $rows = $db->fetchAll('SELECT id FROM tree where lat between ? and ? and lng between ? and ? and food = ?', $lat_range_micro[0], $lat_range_micro[1], $long_range_micro[0], $long_range_micro[1], $fruit_id);
  if (sizeof($rows) > 1) {
    for ($j = 0; $j < sizeof($rows); $j++) {
      $tree_id[$j] = $rows[j]->{'id'};
    }
  } elseif (sizeof($rows) < 1) {
    # not found, do bigger search
    $rows = $db->fetchAll('SELECT id FROM tree where lat between ? and ? and lng between ? and ? and food = ?', $lat_range_mini[0], $lat_range_mini[1], $long_range_mini[0], $long_range_mini[1], $fruit_id);
    if (sizeof($rows) > 1) {
      for ($j = 0; $j < sizeof($rows); $j++) {
        $tree_id[$j] = $rows[$j]->{'id'};
      }
    } elseif (sizeof($rows) == 0) {
      $tree_id[0] = tree_upload($latitude, $longitude, $fruit_id);
      if ($tree_id[0] < 0) {
        header('HTTP/1.1 551 No tree');
        header('X-CJ-Error: No tree');
        exit;
      }
    } else {
      $tree_id[0] = $rows[0]->{'id'};
    }
  } else {
    $tree_id[0] = $rows[0]->{'id'};
  }
  for ($i = 0; $i < sizeof($tree_id); $i++) {
    $note_filename = image_upload($tree_id[$i], $file);
    if ($note_filename != '') {
      note_upload($tree_id[$i], $note_filename, $note, $img_date, $from, $rating);
    } else {
      header('HTTP/1.1 552 No Image');
      header('X-CJ-Error: No Image');
      exit;
    }  
  }
  header('HTTP/1.1 200 OK');
  
}

function image_upload($id, $image) {
  $url = "https://www.concrete-jungle.org/FoodParent2.0/server/imageupload.php";
  $prefix = "note_" . $id;

  if ($image != '') {
    $headers = array("Content-Type:multipart/form-data"); // cURL headers for file uploading
    $cfile = curl_file_create($image['tmp_name'],'image/jpeg','blob');
    $postfields = array("file" => $cfile, "prefix" => $prefix);
    $ch = curl_init();
    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_POST => 1,
        CURLOPT_SAFE_UPLOAD => 1,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_INFILESIZE => $image['size'],
        CURLOPT_RETURNTRANSFER => true
    ); // cURL options
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if(!curl_errno($ch)) {
        $info = curl_getinfo($ch);
        if ($info['http_code'] == 200) {
            $filename = end(explode('/', json_decode($response)->{'files'}[0]));
            $errmsg = "File uploaded successfully";
            return $filename;
        }
    } else {
        print_r(curl_error($ch));
        $errmsg = curl_error($ch);
        return '';
    }
    curl_close($ch);
  } else {
    $errmsg = "Please select the file";
    return '';
  }  
}

function note_upload($id, $filename, $note, $img_date, $from, $rating) {
  $url = "https://www.concrete-jungle.org/FoodParent2.0/server/note.php";

  if ($filename != '') {
    $headers = array("Content-Type:application/json");
    $postfields = array("amount" => 0, "comment" => $note, "date" => $img_date, "id" => 0, "person" => $from, "picture" => $filename, "rate" => $rating, "tree" => $id, "type" => 2);
    $ch = curl_init();
    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($postfields),
        CURLOPT_RETURNTRANSFER => true
    ); // cURL options
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if(!curl_errno($ch)) {
        $info = curl_getinfo($ch);
        if ($info['http_code'] == 200) {
            $errmsg = "File uploaded successfully";
            return 1;
        }
    } else {
        print_r(curl_error($ch));
        $errmsg = curl_error($ch);
        return 0;
    }
    curl_close($ch);
  } else {
    return 0;
  }
}

function tree_upload($lat, $lng, $food_id) {
  $url = "https://www.concrete-jungle.org/FoodParent2.0/server/tree.php";

  if ($food_id != '' && $lat != '' && $lng != '') {
    $headers = array("Content-Type:application/json");
    $postfields = array("description" => "Added via email updater", "address" => '', "lat" => $lat, "lng" => $lng, "owner" => 0, "rate" => -1, "parent" => 0, "public" => 0, "food" => $food_id, "id" => 0, "flag" => 0, "dead" => 0);
    $ch = curl_init();
    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($postfields),
        CURLOPT_RETURNTRANSFER => true
    ); // cURL options
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);

    if(!curl_errno($ch)) {
        $info = curl_getinfo($ch);
        if ($info['http_code'] == 200) {
            $errmsg = "File uploaded successfully";
            $id = json_decode($response)->{'tree'}->{'id'};
            return $id;
        }
    } else {
        print_r(curl_error($ch));
        $errmsg = curl_error($ch);
        return -1;
    }
    curl_close($ch);
  } else {
    return -1;
  }
}


function check_signature($timestamp, $token, $signature) {
  return hash_hmac("sha256", $timestamp . $token, "key-193010289ddb1cb8f5e65907301240ee") === $signature;
}

function pluralize($quantity, $singular, $plural=null) {
    if($quantity==1 || !strlen($singular)) return $singular;
    if($plural!==null) return $plural;

    $last_letter = strtolower($singular[strlen($singular)-1]);
    switch($last_letter) {
        case 'y':
            return substr($singular,0,-1).'ies';
        case 's':
            return $singular.'es';
        default:
            return $singular.'s';
    }
}

class my_db {

    private static $databases;
    private $connection;

    public function __construct($connDetails){
        if(!is_object(self::$databases[$connDetails])){
            list($host, $user, $pass, $dbname) = explode('|', $connDetails);
            $dsn = "mysql:host=$host;dbname=$dbname";
            self::$databases[$connDetails] = new PDO($dsn, $user, $pass);
        }
        $this->connection = self::$databases[$connDetails];
    }
    
    public function fetchAll($sql){
        $args = func_get_args();
        array_shift($args);
        $statement = $this->connection->prepare($sql);        
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_OBJ);
    }
}

?>

