<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require 'vendor/autoload.php';
use Stichoza\GoogleTranslate\GoogleTranslate;

/* =========================
 KONEKSI DB
========================= */
$conn = new mysqli("localhost", "root", " ", "db_name");
if ($conn->connect_error) {
    echo json_encode(["error" => "DB connection failed"]);
    exit;
}

/* =========================
    INPUT
========================= */
$q = strtolower(trim($_GET['q'] ?? ''));
$count = 10;

if ($q == '' || strlen($q) < 3) {
    echo json_encode([]);
    exit;
}

/* =========================
    FUNCTION TRANSLATE + CACHE
========================= */
function translateCache($conn, $text, $source='id', $target='en') {

    // normalize
    $text = strtolower(trim($text));

    //  cek cache
    $stmt = $conn->prepare("SELECT translated_text FROM translations 
        WHERE source_text=? AND source_lang=? AND target_lang=? LIMIT 1");
    $stmt->bind_param("sss", $text, $source, $target);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        return $row['translated_text'];
    }

    //  translate (library)
    try {
        $tr = new GoogleTranslate($target);
        $tr->setSource($source);
        $translated = $tr->translate($text);
    } catch (Exception $e) {
        return $text;
    }

    //  simpan cache
    $stmt = $conn->prepare("INSERT IGNORE INTO translations 
        (source_text, source_lang, target_lang, translated_text) 
        VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $text, $source, $target, $translated);
    $stmt->execute();

    return $translated;
}

/* =========================
    1. TRANSLATE INPUT → EN
========================= */
$q_en = translateCache($conn, $q, 'id', 'en');

/* =========================
    2. CEK CACHE SNOMED
========================= */
$stmt = $conn->prepare("SELECT result FROM snomed_cache WHERE keyword=? LIMIT 1");
$stmt->bind_param("s", $q_en);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo $row['result'];
    exit;
}

/* =========================
    3. HIT FHIR SNOMED
========================= */
$url = "http://serversnomed:8080/fhir/ValueSet/\$expand"
     . "?url=http://snomed.info/sct?fhir_vs"
     . "&filter=" . urlencode($q_en)
     . "&count=$count";

$response = @file_get_contents($url);

if ($response === false) {
    echo json_encode([
        "error" => "FHIR server not reachable"
    ]);
    exit;
}

$json = json_decode($response, true);
$result = [];

/* =========================
    4. FORMAT RESULT (NO MASS TRANSLATE)
========================= */
if (isset($json['expansion']['contains'])) {
    foreach ($json['expansion']['contains'] as $item) {

        $result[] = [
            'id' => $item['code'],
            'code' => $item['code'],
            'display_en' => $item['display'],
            'display_id' => $item['display'], //  tidak translate massal
            'text' => $item['display'] . " (" . $item['code'] . ")"
        ];
    }
}

/* =========================
    5. SORTING (RELEVANSI)
========================= */
usort($result, function($a, $b) use ($q_en) {
    return stripos($a['display_en'], $q_en) <=> stripos($b['display_en'], $q_en);
});

/* =========================
    6. SIMPAN CACHE
========================= */
$jsonResult = json_encode($result);

$stmt = $conn->prepare("INSERT IGNORE INTO snomed_cache (keyword, result) VALUES (?, ?)");
$stmt->bind_param("ss", $q_en, $jsonResult);
$stmt->execute();

/* =========================
    OUTPUT
========================= */
echo $jsonResult;