<?php

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Exception;

class PocketMineMigrator {
    private $directory;
    private $changes = [];
    private $warnings = [];
    
    private $apiVersionMappings = [
        '4.0.0' => '5.0.0',
        '^4.0.0' => '^5.0.0',
        '~4.0.0' => '~5.0.0'
    ];
    
    private $namespaceReplacements = [
        'pocketmine\\world\\sound\\' => 'pocketmine\\world\\sound\\',
        'pocketmine\\world\\particle\\' => 'pocketmine\\world\\particle\\',
        'pocketmine\\network\\mcpe\\protocol\\' => 'pocketmine\\network\\mcpe\\protocol\\',
    ];
    
    private $classReplacements = [
        'Player::sendMessage' => 'Player::sendMessage',
        'Player::getInventory' => 'Player::getInventory',
        'Server::getPlayerByPrefix' => 'Server::getPlayerByPrefix',
        'World::dropItem' => 'World::dropItem',
        'Block::place' => 'Block::place',
        'Item::getCustomName' => 'Item::getCustomName',
        'Entity::teleport' => 'Entity::teleport',
    ];
    
    private $methodSignatureChanges = [
        'public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool' =>
        'public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool',
        
        'public function onEnable(): void' => 'public function onEnable(): void',
        'public function onDisable(): void' => 'public function onDisable(): void',
        'public function onLoad(): void' => 'public function onLoad(): void',
    ];
    
    private $deprecatedMethods = [
        'Player::sendPopup' => 'Player::sendTip',
        'Player::sendTip' => 'Player::sendActionBarMessage',
        'Server::getOfflinePlayerData' => 'Server::getOfflinePlayer',
        'World::getFolderName' => 'World::getDisplayName',
    ];
    
    private $eventChanges = [
        'PlayerJoinEvent' => 'PlayerJoinEvent',
        'PlayerQuitEvent' => 'PlayerQuitEvent',
        'BlockBreakEvent' => 'BlockBreakEvent',
        'BlockPlaceEvent' => 'BlockPlaceEvent',
        'EntityDamageEvent' => 'EntityDamageEvent',
        'PlayerInteractEvent' => 'PlayerInteractEvent',
        'PlayerChatEvent' => 'PlayerChatEvent',
    ];
    
    private $configChanges = [
        'plugin.yml' => [
            'api' => '5.0.0',
            'mcpe-protocol' => 'removed' // This field was removed in 5.0.0
        ]
    ];
    
    public function __construct($directory) {
        $this->directory = realpath($directory);
        if (!$this->directory || !is_dir($this->directory)) {
            throw new Exception("Directory does not exist: $directory");
        }
    }
    
    public function migrate() {
        echo "Starting migration from API 4.0.0 to 5.0.0...\n";
        echo "Processing directory: {$this->directory}\n\n";
        
        // Process plugin.yml first
        $this->processPluginYml();
        
        // Process PHP files
        $this->processPhpFiles();
        
        // Process composer.json if exists
        $this->processComposerJson();
        
        // Create backup
        $this->createBackup();
        
        // Apply changes
        $this->applyChanges();
        
        // Show summary
        $this->showSummary();
    }
    
    private function processPluginYml() {
        $pluginYml = $this->directory . '/plugin.yml';
        if (!file_exists($pluginYml)) {
            $this->warnings[] = "plugin.yml not found";
            return;
        }
        
        $content = file_get_contents($pluginYml);
        $originalContent = $content;
        
        $content = preg_replace('/^api:\s*["\']*4\.0\.0["\']*$/m', 'api: "5.0.0"', $content);
        $content = preg_replace('/^api:\s*["\']*\^4\.0\.0["\']*$/m', 'api: "^5.0.0"', $content);
        $content = preg_replace('/^api:\s*["\']*~4\.0\.0["\']*$/m', 'api: "~5.0.0"', $content);
        
        $content = preg_replace('/^mcpe-protocol:.*$/m', '', $content);
        
        if ($content !== $originalContent) {
            $this->changes[] = [
                'file' => $pluginYml,
                'original' => $originalContent,
                'modified' => $content,
                'type' => 'plugin.yml'
            ];
        }
    }
    
    private function processPhpFiles() {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->processPhpFile($file->getPathname());
            }
        }
    }
    
    private function processPhpFile($filepath) {
        $content = file_get_contents($filepath);
        $originalContent = $content;
        
        foreach ($this->namespaceReplacements as $old => $new) {
            if ($old !== $new) {
                $content = str_replace($old, $new, $content);
            }
        }
        
        foreach ($this->deprecatedMethods as $old => $new) {
            if ($old !== $new) {
                $content = str_replace($old, $new, $content);
                $this->warnings[] = "Replaced deprecated method $old with $new in $filepath";
            }
        }
        
        foreach ($this->classReplacements as $old => $new) {
            if ($old !== $new) {
                $content = str_replace($old, $new, $content);
            }
        }
        
        $this->checkForSpecificChanges($content, $filepath);
        
        $content = $this->updateEventUseStatements($content);
        
        $content = $this->updateMethodSignatures($content);
        
        if ($content !== $originalContent) {
            $this->changes[] = [
                'file' => $filepath,
                'original' => $originalContent,
                'modified' => $content,
                'type' => 'php'
            ];
        }
    }
    
    private function checkForSpecificChanges($content, $filepath) {
        if (strpos($content, '->sendPopup(') !== false) {
            $this->warnings[] = "Found sendPopup() usage in $filepath - this method was removed. Use sendTip() instead.";
        }
        
        if (strpos($content, '->getFolderName()') !== false) {
            $this->warnings[] = "Found getFolderName() usage in $filepath - use getDisplayName() instead.";
        }
        
        if (strpos($content, 'InventoryTransactionEvent') !== false) {
            $this->warnings[] = "Found InventoryTransactionEvent usage in $filepath - this event was restructured in 5.0.0.";
        }
        
        if (strpos($content, '->getMeta()') !== false || strpos($content, '->setMeta(') !== false) {
            $this->warnings[] = "Found block metadata usage in $filepath - block metadata system was changed in 5.0.0.";
        }
    }
    
    private function updateEventUseStatements($content) {
        $eventUpdates = [
            'use pocketmine\\event\\player\\PlayerJoinEvent;' => 'use pocketmine\\event\\player\\PlayerJoinEvent;',
            'use pocketmine\\event\\player\\PlayerQuitEvent;' => 'use pocketmine\\event\\player\\PlayerQuitEvent;',
            'use pocketmine\\event\\block\\BlockBreakEvent;' => 'use pocketmine\\event\\block\\BlockBreakEvent;',
            'use pocketmine\\event\\block\\BlockPlaceEvent;' => 'use pocketmine\\event\\block\\BlockPlaceEvent;',
        ];
        
        foreach ($eventUpdates as $old => $new) {
            $content = str_replace($old, $new, $content);
        }
        
        return $content;
    }
    
    private function updateMethodSignatures($content) {
        foreach ($this->methodSignatureChanges as $old => $new) {
            $content = str_replace($old, $new, $content);
        }
        
        return $content;
    }
    
    private function processComposerJson() {
        $composerJson = $this->directory . '/composer.json';
        if (!file_exists($composerJson)) {
            return;
        }
        
        $content = file_get_contents($composerJson);
        $originalContent = $content;
        $data = json_decode($content, true);
        
        if (isset($data['require']['pocketmine/pocketmine-mp'])) {
            $version = $data['require']['pocketmine/pocketmine-mp'];
            if (strpos($version, '4.0.0') !== false) {
                $data['require']['pocketmine/pocketmine-mp'] = str_replace('4.0.0', '5.0.0', $version);
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }
        
        if ($content !== $originalContent) {
            $this->changes[] = [
                'file' => $composerJson,
                'original' => $originalContent,
                'modified' => $content,
                'type' => 'composer.json'
            ];
        }
    }
    
    private function createBackup() {
        $backupDir = $this->directory . '_backup_' . date('Y-m-d_H-i-s');
        echo "Creating backup at: $backupDir\n";
        
        $this->copyDirectory($this->directory, $backupDir);
    }
    
    private function copyDirectory($source, $dest) {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item, $target);
            }
        }
    }
    
    private function applyChanges() {
        echo "Applying " . count($this->changes) . " changes...\n";
        
        foreach ($this->changes as $change) {
            file_put_contents($change['file'], $change['modified']);
            echo "Updated: " . basename($change['file']) . "\n";
        }
    }
    
    private function showSummary() {
        echo "\n=== Migration Summary ===\n";
        echo "Files modified: " . count($this->changes) . "\n";
        echo "Warnings: " . count($this->warnings) . "\n\n";
        
        if (!empty($this->warnings)) {
            echo "=== Warnings ===\n";
            foreach ($this->warnings as $warning) {
                echo "⚠️  $warning\n";
            }
            echo "\n";
        }
        
        echo "=== Manual Review Required ===\n";
        echo "• Check all event handlers for signature changes\n";
        echo "• Review inventory transaction event usage\n";
        echo "• Test block metadata interactions\n";
        echo "• Verify permission system usage\n";
        echo "• Check custom item/block implementations\n";
        echo "• Review network protocol usage\n\n";
        
        echo "Migration complete! Please test your plugin thoroughly.\n";
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php migrate.php <plugin_directory>\n";
        exit(1);
    }
    
    $directory = $argv[1];
    
    try {
        $migrator = new PocketMineMigrator($directory);
        $migrator->migrate();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>
