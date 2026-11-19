<?php
// File: /api/search.php
// NOTE: No strict_types; compatible with older PHP.
header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../inc/db_compat.php';

try {
  $pdo = get_pdo();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
}

function like_term($q) { return '%' . preg_replace('/\s+/', ' ', $q) . '%'; }
function fail($m, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function dt_range_sql($field, $from, $to, &$params) {
  $sql = '';
  if ($from) { $sql .= " AND $field >= :from_dt"; $params[':from_dt'] = $from . ' 00:00:00'; }
  if ($to)   { $sql .= " AND $field <= :to_dt";   $params[':to_dt']   = $to   . ' 23:59:59'; }
  return $sql;
}

$q        = trim($_GET['q'] ?? '');
$scope    = $_GET['scope'] ?? 'all';
$from     = $_GET['from'] ?? null;
$to       = $_GET['to'] ?? null;
$category = $_GET['category'] ?? null;
$supplier = $_GET['supplier'] ?? null;
$limit    = max(5, min((int)($_GET['limit'] ?? 10), 50));

if ($scope === 'all' && $q === '') fail('Empty query');

$res = [];

/* PRODUCTS */
if ($scope === 'all' || $scope === 'products') {
  $params = [':like'=>like_term($q)];
  $where = [];
  $select = ['id','name'];
  if (column_exists($pdo, 'products', 'sku')) { $where[] = 'sku LIKE :like'; $select[]='sku'; }
  if (column_exists($pdo, 'products', 'description')) { $where[] = 'description LIKE :like'; }
  $where[] = 'name LIKE :like';

  $whereSql = '(' . implode(' OR ', $where) . ')';

  // Optional filters
  if ($category && column_exists($pdo, 'products','category_id')) { $whereSql .= ' AND category_id = :cat'; $params[':cat'] = $category; }
  if ($supplier && column_exists($pdo, 'products','supplier_id')) { $whereSql .= ' AND supplier_id = :sup'; $params[':sup'] = $supplier; }

  $selectSql = implode(',', array_unique($select));
  $sql = "SELECT $selectSql FROM products WHERE $whereSql ORDER BY name ASC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $res['products'] = array_map(function($r){
    $sub = [];
    if (isset($r['sku']) && $r['sku']!=='') $sub[] = 'SKU '.$r['sku'];
    return [
      'id'=>$r['id'],
      'title'=>$r['name'],
      'subtitle'=>implode(' · ', $sub),
      'href'=>"/public/?page=product&id=".$r['id'],
    ];
  }, $rows);
}

/* SALES */
if ($scope === 'all' || $scope === 'sales') {
  $params = [':like'=>like_term($q)];
  $where = [];
  if (column_exists($pdo,'sales','reference')) $where[] = 'reference LIKE :like';
  if (column_exists($pdo,'sales','note'))      $where[] = 'note LIKE :like';
  if (!$where) $where[] = 'id LIKE :like';
  $whereSql = '(' . implode(' OR ', $where) . ')';
  $whereSql .= dt_range_sql('sale_date',$from,$to,$params);

  $select = ['id'];
  if (column_exists($pdo,'sales','reference')) $select[]='reference';
  if (column_exists($pdo,'sales','sale_date')) $select[]='sale_date';
  if (column_exists($pdo,'sales','total'))     $select[]='total';

  $sql = "SELECT ".implode(',', $select)." FROM sales WHERE $whereSql ORDER BY ".(in_array('sale_date',$select)?'sale_date':'id')." DESC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $res['sales'] = array_map(function($r){
    $title = 'Sale #'.($r['reference'] ?? $r['id']);
    $sub = [];
    if (!empty($r['sale_date'])) $sub[] = date('Y-m-d', strtotime($r['sale_date']));
    if (isset($r['total'])) $sub[] = number_format((float)$r['total'],2);
    return [
      'id'=>$r['id'], 'title'=>$title, 'subtitle'=>implode(' · ',$sub),
      'href'=>"/public/?page=sale&id=".$r['id'],
    ];
  }, $rows);
}

/* PURCHASES */
if ($scope === 'all' || $scope === 'purchases') {
  $params = [':like'=>like_term($q)];
  $where = [];
  if (column_exists($pdo,'purchases','reference')) $where[]='reference LIKE :like';
  if (column_exists($pdo,'purchases','note'))      $where[]='note LIKE :like';
  if (!$where) $where[] = 'id LIKE :like';
  $whereSql = '(' . implode(' OR ', $where) . ')';
  if ($supplier && column_exists($pdo,'purchases','supplier_id')) { $whereSql .= ' AND supplier_id = :sup'; $params[':sup']=$supplier; }
  $whereSql .= dt_range_sql('purchase_date',$from,$to,$params);

  $select = ['id'];
  if (column_exists($pdo,'purchases','reference'))     $select[]='reference';
  if (column_exists($pdo,'purchases','purchase_date')) $select[]='purchase_date';
  if (column_exists($pdo,'purchases','total_cost'))    $select[]='total_cost';

  $sql = "SELECT ".implode(',', $select)." FROM purchases WHERE $whereSql ORDER BY ".(in_array('purchase_date',$select)?'purchase_date':'id')." DESC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $res['purchases'] = array_map(function($r){
    $title = 'Purchase #'.($r['reference'] ?? $r['id']);
    $sub = [];
    if (!empty($r['purchase_date'])) $sub[] = date('Y-m-d', strtotime($r['purchase_date']));
    if (isset($r['total_cost'])) $sub[] = number_format((float)$r['total_cost'],2);
    return [
      'id'=>$r['id'], 'title'=>$title, 'subtitle'=>implode(' · ',$sub),
      'href'=>"/public/?page=purchase&id=".$r['id'],
    ];
  }, $rows);
}

/* CUSTOMERS */
if ($scope === 'all' || $scope === 'customers') {
  $params = [':like'=>like_term($q)];
  $where = [];
  foreach (['name','phone','email'] as $f) if (column_exists($pdo,'customers',$f)) $where[]="$f LIKE :like";
  if (!$where) $where[]='id LIKE :like';
  $sql = "SELECT id," . implode(',', array_filter(['name','phone','email'], fn($f)=>column_exists($pdo,'customers',$f))) . " FROM customers WHERE (".implode(' OR ',$where).") ORDER BY ".(column_exists($pdo,'customers','name')?'name':'id')." ASC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':like', like_term($q));
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $res['customers'] = array_map(fn($r)=>[
    'id'=>$r['id'],
    'title'=>$r['name'] ?? ('Customer #'.$r['id']),
    'subtitle'=>trim(($r['phone']??'').'  '.($r['email']??'')),
    'href'=>"/public/?page=customer&id=".$r['id'],
  ], $rows);
}

/* CREDITORS / DEBTORS */
if ($scope === 'all' || in_array($scope, ['creditors','debtors'], true)) {
  $table = $scope==='debtors' ? 'debtors' : 'creditors';
  // If table doesn’t exist, skip gracefully
  try {
    $params = [':like'=>like_term($q)];
    $where = [];
    $nameCol = column_exists($pdo,$table,'party_name') ? 'party_name' : (column_exists($pdo,$table,'name') ? 'name' : 'id');
    $noteCol = column_exists($pdo,$table,'note') ? ' OR note LIKE :like' : '';
    $balanceCol = column_exists($pdo,$table,'balance') ? 'balance' : null;
    $updatedCol = column_exists($pdo,$table,'updated_at') ? 'updated_at' : null;

    $sql = "SELECT id, $nameCol AS pname"
         . ($balanceCol? ", $balanceCol AS bal" : "")
         . ($updatedCol? ", $updatedCol AS updated" : "")
         . " FROM $table WHERE ($nameCol LIKE :like".$noteCol.") ORDER BY "
         . ($balanceCol? "ABS($balanceCol) DESC, " : "")
         . ($updatedCol? "$updatedCol DESC" : "id DESC")
         . " LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':like', like_term($q));
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $res[$table] = array_map(function($r) use ($table){
      $sub = [];
      if (isset($r['bal'])) $sub[] = 'Balance: '.number_format((float)$r['bal'],2);
      if (isset($r['updated'])) $sub[] = date('Y-m-d', strtotime($r['updated']));
      return [
        'id'=>$r['id'],
        'title'=>$r['pname'] ?? ($table.' #'.$r['id']),
        'subtitle'=>implode(' · ', $sub),
        'href'=>"/public/?page=$table&id=".$r['id'],
      ];
    }, $rows);
  } catch (Throwable $e) {
    // silently ignore if table missing
  }
}

/* USERS (admin only) */
if (($scope === 'all' || $scope === 'users') && current_user_can('manage_users')) {
  $params = [':like'=>like_term($q)];
  $selectCols = array_filter(['name','email','username','role'], fn($c)=>column_exists($pdo,'users',$c));
  $sel = $selectCols ? implode(',', $selectCols) : 'id';
  $where = [];
  foreach (['name','email','username'] as $f) if (column_exists($pdo,'users',$f)) $where[]="$f LIKE :like";
  if (!$where) $where[]='id LIKE :like';
  $sql = "SELECT id,$sel FROM users WHERE (".implode(' OR ',$where).") ORDER BY ".(column_exists($pdo,'users','name')?'name':'id')." ASC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':like', like_term($q));
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $res['users'] = array_map(function($r){
    $title = ($r['name'] ?? 'User #'.$r['id']) . (isset($r['role']) ? ' ('.$r['role'].')' : '');
    $sub = $r['email'] ?? ($r['username'] ?? '');
    return ['id'=>$r['id'], 'title'=>$title, 'subtitle'=>$sub, 'href'=>"/public/?page=user&id=".$r['id']];
  }, $rows);
}

/* FILES under /api */
if ($scope === 'all' || $scope === 'files') {
  $needle = $q;
  $apiDir = realpath(__DIR__);
  $hits = [];
  if ($needle !== '' && $apiDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));
    foreach ($it as $file) {
      if (!$file->isFile()) continue;
      $path = $file->getRealPath();
      if (!preg_match('/\.(php|json)$/i', $path)) continue;
      if (strpos($path, $apiDir) !== 0) continue;

      $lines = @file($path, FILE_IGNORE_NEW_LINES);
      if (!$lines) continue;
      $snips = [];
      foreach ($lines as $i=>$line) {
        if (stripos($line, $needle) !== false) {
          $start = max(0,$i-1); $end=min(count($lines)-1,$i+1);
          $blk = [];
          for ($j=$start;$j<=$end;$j++) $blk[] = sprintf('%5d  %s',$j+1,rtrim($lines[$j]));
          $snips[] = implode("\n",$blk);
          if (count($snips) >= 5) break;
        }
      }
      if ($snips) {
        $rel = ltrim(str_replace($apiDir, '', $path), DIRECTORY_SEPARATOR);
        $hits[] = [
          'id'=>$rel,
          'title'=>$rel,
          'subtitle'=>'Matches: '.count($snips),
          'href'=>"/public/?page=file_viewer&f=".urlencode($rel)."&q=".urlencode($needle),
          'snippets'=>$snips,
        ];
        if (count($hits) >= 30) break;
      }
    }
  }
  $res['files'] = $hits;
}

echo json_encode(['ok'=>true,'data'=>$res], JSON_UNESCAPED_SLASHES);
