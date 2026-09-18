<?php
function csrf_token() {
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['_csrf'];
}
function csrf_check($token) {
  $sess = isset($_SESSION['_csrf']) ? $_SESSION['_csrf'] : '';
  return is_string($token) && $token !== '' && $sess !== '' && hash_equals($sess, $token);
}
