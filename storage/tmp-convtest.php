<?php
// OnlyOffice 端到端转换测试：验证 DS 能否下载并转换指定 collab 文档
$docId = (int) ($argv[1] ?? 18);

$row = trim((string) shell_exec("mysql -uroot -proot pm_db -N -e 'SELECT CONCAT(project_id,0x7c,storage_path,0x7c,doc_key,0x7c,size) FROM pm_db.collab_documents WHERE id={$docId}'"));
echo "row: {$row}\n";
if ($row === '') { echo "doc {$docId} not found\n"; exit(1); }
[$projectId, $storagePath, $key, $size] = explode('|', $row);

$envLine = trim((string) shell_exec("grep '^ONLYOFFICE_JWT_SECRET=' /var/www/pm/.env"));
$secret = substr($envLine, strlen('ONLYOFFICE_JWT_SECRET='));

echo "exists on disk: ";
var_dump(is_file("/var/www/pm/storage/app/file-repository/{$storagePath}"));

$url = "http://192.168.2.20/collab/download/{$docId}?k=" . urlencode($key);

// 1. 模拟 DS 下载
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$csize = strlen((string) $body);
curl_close($ch);
echo "download: HTTP {$code}, {$csize} bytes\n";

// 2. 调用 ConvertService 转换（等价于编辑器打开时的格式校验/转换）
$b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$req = [
    'async' => false,
    'filetype' => 'pptx',
    'outputtype' => 'pdf',
    'key' => 'convtest' . $docId . time(),
    'url' => $url,
    'title' => "doc{$docId}.pptx",
];
$header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = $b64(json_encode($req));
$sig = $b64(hash_hmac('sha256', "$header.$payload", $secret, true));

$ch = curl_init('http://192.168.2.20:8080/converter');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $header.$payload.$sig"],
    CURLOPT_POSTFIELDS => json_encode($req),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "convert: HTTP {$code}\n" . substr((string) $resp, 0, 800) . "\n";
