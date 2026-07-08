<?php
use OCA\EducAI\AppInfo\Application;
use OCP\IURLGenerator;

$adminScriptPath = __DIR__ . '/../js/educai-admin.mjs';
$adminScriptVersion = is_file($adminScriptPath) ? (string)filemtime($adminScriptPath) : '0';
$adminScriptUrl = \OCP\Server::get(IURLGenerator::class)->linkTo(Application::APP_ID, 'js/educai-admin.mjs', ['v' => $adminScriptVersion]);
?>

<div id="educai-admin-root"></div>
<?php emit_script_tag($adminScriptUrl, '', 'module'); ?>
