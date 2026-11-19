<?php
// lib/functions.php — core helpers (safe to include everywhere)

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* ---------- HTML escape ---------- */
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* ---------- Money formatting ---------- */
if (!function_exists('money')) {
  function money($amount, string $currency = 'UGX', int $decimals = 2): string {
    $n = (float)$amount;
    return $currency . ' ' . number_format($n, $decimals);
  }
}

/* ---------- Safe redirect (works even if output started) ---------- */
if (!function_exists('safe_redirect')) {
  function safe_redirect(string $url): void {
    if (!headers_sent()) { header('Location: '.$url); exit; }
    echo '<script>window.location.href='.json_encode($url).'</script>';
    echo '<meta http-equiv="refresh" content="0;url='.e($url).'">';
    exit;
  }
}

/* ---------- CSRF helpers ---------- */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
  }
}
if (!function_exists('csrf_check')) {
  function csrf_check(): void {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) { http_response_code(400); die('Invalid CSRF token'); }
  }
}

/* ---------- Current user helpers ---------- */
if (!function_exists('current_user_id')) {
  function current_user_id(): ?int { return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null; }
}
if (!function_exists('current_user')) {
  function current_user(): ?array { return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null; }
}
if (!function_exists('is_admin')) {
  function is_admin(): bool { return (($_SESSION['user']['role'] ?? '') === 'admin'); }
}

/* ---------- Capability helper (from user_caps) ---------- */
if (!function_exists('can_view_cost')) {
  function can_view_cost(): bool {
    $caps = $_SESSION['user']['perms'] ?? [];
    return is_admin() || in_array('view_costs', $caps, true);
  }
}

/* ---------- Audit wrapper (writes into audit_logs) ---------- */
if (!function_exists('audit')) {
  function audit(string $action, string $entityType = null, ?int $entityId = null, string $message = null, string $page = null): void {
    try {
      global $pdo;
      $uid  = current_user_id();
      $user = $_SESSION['user']['username'] ?? null;
      $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
      $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
      $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, message, page, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$uid, $user, $action, $entityType, $entityId, $message, $page, $ip, $ua]);
    } catch (\Throwable $e) { /* ignore */ }
  }
}

/* ---------- Code128 barcode SVG generator (Set B) ---------- */
/**
 * Generate a Code128B SVG barcode.
 * @param string $code   Text to encode (ASCII 32–126 recommended)
 * @param int    $height Bar height (px)
 * @param int    $scale  Pixel width of a single module
 * @return string SVG markup
 */
if (!function_exists('barcode_code128_svg')) {
  function barcode_code128_svg(string $code, int $height = 40, int $scale = 2): string {
    // Code128B patterns indexed by char position (32..126)
    $codes = [
      ' '=> '11011001100','!'=>'11001101100','"'=>'11001100110','#'=>'10010011000','$'=>'10010001100','%'=>'10001001100','&'=>'10011001000','\''=>'10011000100',
      '('=>'10001100100',')'=>'11001001000','*'=>'11001000100','+'=>'11000100100',','=>'10110011100','-'=>'10011011100','.'=>'10011001110','/'=>'10111001100',
      '0'=>'10011101100','1'=>'10011100110','2'=>'11001110010','3'=>'11001011100','4'=>'11001001110','5'=>'11011100100','6'=>'11001110100','7'=>'11101101110',
      '8'=>'11101001100','9'=>'11100101100',':'=>'11100100110',';'=>'11101100100','<'=>'11100110100','='=>'11100110010','>'=>'11011011000','?'=>'11011000110',
      '@'=>'11000110110','A'=>'10100011000','B'=>'10001011000','C'=>'10001000110','D'=>'10110001000','E'=>'10001101000','F'=>'10001100010','G'=>'11010001000',
      'H'=>'11000101000','I'=>'11000100010','J'=>'10110111000','K'=>'10110001110','L'=>'10001101110','M'=>'10111011000','N'=>'10111000110','O'=>'10001110110',
      'P'=>'11101110110','Q'=>'11010001110','R'=>'11000101110','S'=>'11011101000','T'=>'11011100010','U'=>'11011101110','V'=>'11101011000','W'=>'11101000110',
      'X'=>'11100010110','Y'=>'11101101000','Z'=>'11101100010','['=>'11100011010','\\'=>'11101111010',']'=>'11001000010','^'=>'11110001010','_'=>'10100110000',
      '`'=>'10100001100','a'=>'10010110000','b'=>'10010000110','c'=>'10000101100','d'=>'10000100110','e'=>'10110010000','f'=>'10110000100','g'=>'10011010000',
      'h'=>'10011000010','i'=>'10000110100','j'=>'10000110010','k'=>'11000010010','l'=>'11001010000','m'=>'11110111010','n'=>'11000010100','o'=>'10001111010',
      'p'=>'10100111100','q'=>'10010111100','r'=>'10010011110','s'=>'10111100100','t'=>'10011110100','u'=>'10011110010','v'=>'11110100100','w'=>'11110010100',
      'x'=>'11110010010','y'=>'11011011110','z'=>'11011110110','{'=>'11110110110','|'=>'10101111000','}'=>'10100011110','~'=>'10001011110'
    ];
    $startB = '11010010000'; // Start Code B
    $stop   = '1100011101011';

    // Build sequence and checksum
    $seq = $startB;
    $checksum = 104; // Start B value
    $i = 0;
    $keys = array_keys($codes);
    $posMap = array_flip($keys); // char => index 0..94
    $len = strlen($code);
    for ($i=0; $i<$len; $i++) {
      $ch = $code[$i];
      if (!isset($posMap[$ch])) continue; // skip unsupported chars
      $idx = $posMap[$ch];        // 0..94
      $val = 32 + $idx;           // Code128 value mapping (B set starts at 32)
      $seq .= $codes[$ch];
      $checksum += $val * ($i+1);
    }
    $checkVal = $checksum % 103;

    // Find pattern for checkVal in B (32..126 map)
    // We reverse-map: value 32..126 => index 0..94 => keys[index]
    $valToKeyIndex = $checkVal - 32;
    if ($valToKeyIndex >= 0 && $valToKeyIndex < count($keys)) {
      $seq .= $codes[$keys[$valToKeyIndex]];
    } else {
      // Fallback (shouldn't happen): add a neutral pattern
      $seq .= '11011001100';
    }
    $seq .= $stop;

    // Draw SVG
    $width = strlen($seq) * $scale;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">';
    $x = 0;
    $n = strlen($seq);
    for ($i = 0; $i < $n; $i++) {
      if ($seq[$i] === '1') {
        $svg .= '<rect x="'.$x.'" y="0" width="'.$scale.'" height="'.$height.'" fill="black"/>';
      }
      $x += $scale;
    }
    $svg .= '</svg>';
    return $svg;
  }
}

/* ---------- Simple device label from User-Agent ---------- */
if (!function_exists('ua_device_label')) {
  function ua_device_label(?string $ua): string {
    $u = strtolower((string)$ua);
    if ($u === '') return 'Unknown';
    if (str_contains($u,'iphone')) return 'iPhone';
    if (str_contains($u,'ipad')) return 'iPad';
    if (str_contains($u,'android')) return 'Android';
    if (str_contains($u,'windows')) return 'Windows';
    if (str_contains($u,'mac os x') || str_contains($u,'macintosh')) return 'macOS';
    if (str_contains($u,'linux')) return 'Linux';
    return 'Other';
  }
}

