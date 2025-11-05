<?php
if (!isset($_SESSION)) { session_start(); }
$current = basename($_SERVER['PHP_SELF']);

if (!function_exists('isActive')) {
  function isActive($file) {
    global $current;
    return $current === $file;
  }
}
?>
<div id="radci-mobile-nav">
  <a href="dashboard.php" class="<?= isActive('dashboard.php') ? 'active' : '' ?>" aria-label="Dashboard">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 10.5L12 3l9 7.5v10.5a1.5 1.5 0 0 1-1.5 1.5h-4.5V14.5h-6v8H4.5A1.5 1.5 0 0 1 3 21V10.5z"></path>
    </svg>
    <span>Início</span>
  </a>
  <a href="minhas_ocorrencias.php" class="<?= isActive('minhas_ocorrencias.php') ? 'active' : '' ?>" aria-label="Minhas Ocorrências">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3.5" y="4" width="17" height="16" rx="2"></rect>
      <path d="M7 8h10"></path>
      <path d="M7 12h10"></path>
      <path d="M7 16h6"></path>
    </svg>
    <span>Ocorrências</span>
  </a>
  <a href="minha_conta.php" class="<?= isActive('minha_conta.php') ? 'active' : '' ?>" aria-label="Minha Conta">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="8" r="4"></circle>
      <path d="M4 20c1.8-4 6-6 8-6s6.2 2 8 6"></path>
    </svg>
    <span>Conta</span>
  </a>
</div>

<style>
#radci-mobile-nav {
  position: fixed; bottom: 0; left: 0; right: 0;
  height: 64px; background: #1a2234; border-top: 1px solid #2d3748;
  display: flex; align-items: center; justify-content: space-around;
  padding: 6px 8px; z-index: 9999;
}
#radci-mobile-nav a {
  flex: 1; text-align: center; text-decoration: none; color: #ffffff;
  font-size: 12px; font-weight: 600; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}
#radci-mobile-nav a .icon { width: 24px; height: 24px; margin-bottom: 4px; color: currentColor; }
#radci-mobile-nav a:nth-child(1) { color: #10b981; }
#radci-mobile-nav a:nth-child(2) { color: #6366f1; }
#radci-mobile-nav a:nth-child(3) { color: #a855f7; }
@media (min-width: 768px) { #radci-mobile-nav { display: none; } }
</style>