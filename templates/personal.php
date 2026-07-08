<?php
use OCA\EducAI\AppInfo\Application;
use OCP\IURLGenerator;

$personalScriptPath = __DIR__ . '/../js/educai-personal.mjs';
$personalScriptVersion = is_file($personalScriptPath) ? (string)filemtime($personalScriptPath) : '0';
$personalScriptUrl = \OCP\Server::get(IURLGenerator::class)->linkTo(Application::APP_ID, 'js/educai-personal.mjs', ['v' => $personalScriptVersion]);
?>

<div id="educai-personal-root"></div>
<?php emit_script_tag($personalScriptUrl, '', 'module'); ?>

