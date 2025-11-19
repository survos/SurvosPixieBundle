<?php

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\PixieContext;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;

class PixieService extends PixieServiceBase {

    /**
     * Switch the shared pixie EM connection to the DB file for $pixieCode,
     * WITHOUT querying any tables. Safe to call before schema exists.
     */
    public function switchToPixieDatabase(string $pixieCode): EntityManagerInterface
    {
        $em = $this->pixieEntityManager;
        $conn = $em->getConnection();

        $targetPath = $this->dbName($pixieCode);
        $currentPath = $conn->getParams()['path'] ?? null;

        $this->logger?->info("=== switchToPixieDatabase START - VERSION 2.2 ===");
        $this->logger?->info("Input pixieCode: {$pixieCode}");
        $this->logger?->info("Target path: {$targetPath}");
        $this->logger?->info("Current path: {$currentPath}");
        $this->logger?->info("Target file exists: " . (file_exists($targetPath) ? 'YES' : 'NO'));
        $this->logger?->info("Connection is connected: " . ($conn->isConnected() ? 'YES' : 'NO'));

        if ($currentPath !== $targetPath) {
            $this->logger?->info("Paths differ - switching database");

            // Test current database before switch
            if ($conn->isConnected()) {
                try {
                    $currentTables = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table'")->fetchFirstColumn();
                    $this->logger?->info("Current database tables BEFORE switch: " . implode(', ', $currentTables));
                } catch (\Exception $e) {
                    $this->logger?->info("Could not query current database before switch: " . $e->getMessage());
                }
            }

            try {
                $this->logger?->info("Flushing EntityManager...");
                $em->flush();
                $this->logger?->info("EntityManager flushed successfully");
            } catch (\Throwable $e) {
                $this->logger?->info("EntityManager flush failed: " . $e->getMessage());
            }

            $this->logger?->info("Clearing EntityManager...");
            $em->clear();
            $this->logger?->info("EntityManager cleared");

            // Force close connection
            if ($conn->isConnected()) {
                $this->logger?->info("Closing existing connection...");
                $conn->close();
                $this->logger?->info("Connection closed. Is connected now: " . ($conn->isConnected() ? 'YES' : 'NO'));
            }

            $this->logger?->info("Calling selectDatabase with: {$targetPath}");
            $conn->selectDatabase($targetPath);
            $this->logger?->info("selectDatabase called");

            // Test connection (this will automatically connect if needed)
            $this->logger?->info("Testing connection...");
            try {
                $testResult = $conn->executeQuery("SELECT 1")->fetchOne();
                $this->logger?->info("Connection test successful, result: " . $testResult);
            } catch (\Exception $e) {
                $this->logger?->error("Connection test failed: " . $e->getMessage());
            }
            $this->logger?->info("Connection is connected now: " . ($conn->isConnected() ? 'YES' : 'NO'));

            // Verify the switch worked
            $newPath = $conn->getParams()['path'] ?? 'UNKNOWN';
            $this->logger?->info("Path after switch: {$newPath}");

            if ($newPath !== $targetPath) {
                $this->logger?->error("DATABASE SWITCH FAILED!");
                $this->logger?->error("Expected: {$targetPath}");
                $this->logger?->error("Actual: {$newPath}");
                throw new \RuntimeException("Failed to switch to database: {$targetPath}");
            }

            // Test new database after switch
            try {
                $newTables = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table'")->fetchFirstColumn();
//                $this->logger?->info("New database tables AFTER switch: " . implode(', ', $newTables));
//                $this->logger?->warning("Owner table present: " . (in_array('owner', $newTables) ? 'YES' : 'NO'));
            } catch (\Exception $e) {
                $this->logger?->error("Could not query new database after switch: " . $e->getMessage());
            }

            $this->logger?->info("Database switch completed successfully");
        } else {
            $this->logger?->info("Paths are the same - no switch needed");

            // Still test what tables are available
            if ($conn->isConnected()) {
                try {
                    $tables = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table'")->fetchFirstColumn();
//                    $this->logger?->info("Current database tables (no switch): " . implode(', ', $tables));
//                    $this->logger?->warning("Owner table present: " . (in_array('owner', $tables) ? 'YES' : 'NO'));
                } catch (\Exception $e) {
                    $this->logger?->info("Could not query current database: " . $e->getMessage());
                }
            }
        }

        $this->currentPixieCode = $pixieCode;
//        $this->logger?->info("=== switchToPixieDatabase END ===");
        return $em;
    }

    /**
     * Ensure the Pixie schema (Owner/Core/Row/etc.) exists in the given EM.
     * Idempotent: if tables already exist, this is a no-op.
     */
    public function ensureSchema(EntityManagerInterface $em): void
    {
        $currentDbPath = $em->getConnection()->getParams()['path'] ?? 'UNKNOWN';
//        $this->logger?->warning("=== ENSURE SCHEMA START ===");
//        $this->logger?->warning("Database path: {$currentDbPath}");
//        $this->logger?->warning("Current pixie code: " . ($this->currentPixieCode ?? 'NULL'));

        // Check if Owner entity file exists first
        $this->checkOwnerEntityFile();

        $sm = $em->getConnection()->createSchemaManager();

        // Check what tables currently exist
        try {
            $existingTables = $sm->listTableNames();
//            $this->logger?->info("Existing tables before schema check: " . implode(', ', $existingTables));
//            $this->logger?->warning("OWNER table exists before schema: " . (in_array('owner', $existingTables) ? 'YES' : 'NO'));
        } catch (\Exception $e) {
            $this->logger?->error("Could not list existing tables: " . $e->getMessage());
        }

        // Bootstrap check on a canonical table
        if ($sm->tablesExist(['owner'])) {
//            $this->logger?->warning("Owner table already exists - schema ensurance complete");
//            $this->logger?->warning("=== ENSURE SCHEMA END (EARLY RETURN) ===");
            return;
        }

//        $this->logger?->warning("Owner table does not exist - proceeding with schema creation");

        // Get all metadata and specifically check for Owner
        $metadataFactory = $em->getMetadataFactory();
        $allMetadata = []; // $metadataFactory->getAllMetadata();

        $this->logger?->info("Total metadata objects found: " . count($allMetadata));

        $pixieClasses = [];
        $ownerMetadata = null;

        foreach ($allMetadata as $meta) {
            $className = $meta->getName();
            $this->logger?->info("Found metadata for: {$className}");

            if (str_starts_with($className, 'Survos\\PixieBundle\\Entity\\')) {
                $pixieClasses[] = $meta;
                $this->logger?->info("Added to Pixie classes: {$className}");

                if ($className === Owner::class) {
                    $ownerMetadata = $meta;
//                    $this->logger?->warning("=== FOUND OWNER METADATA ===");
//                    $this->logger?->warning("Owner class name: {$className}");
//                    $this->logger?->warning("Owner table name: " . $meta->getTableName());
//                    $this->logger?->warning("Owner identifier: " . implode(', ', $meta->getIdentifierFieldNames()));
                }
            }
        }

        // If getAllMetadata() returned few/no results, manually load ALL PixieBundle entities
        if (count($allMetadata) < 5) { // getAllMetadata() is not working properly
            $this->logger?->warning("getAllMetadata() returned insufficient results (" . count($allMetadata) . "), manually loading all PixieBundle entities");

            $pixieEntityClasses = $this->getPixieEntityClasses();
            $this->logger?->warning("Found " . count($pixieEntityClasses) . " PixieBundle entity classes to load");

            $pixieClasses = [];
            $loadedCount = 0;
            $failedCount = 0;

            foreach ($pixieEntityClasses as $entityClass) {
                $this->logger?->info("Attempting to load metadata for: {$entityClass}");
                try {
                    $metadata = $metadataFactory->getMetadataFor($entityClass);
                    if ($metadata) {
                        $pixieClasses[] = $metadata;
                        $loadedCount++;
//                        $this->logger?->info("✓ Successfully loaded metadata for: {$entityClass} -> " . $metadata->getTableName());

                        if ($entityClass === Owner::class) {
                            $ownerMetadata = $metadata;
//                            $this->logger?->warning("=== FOUND OWNER METADATA (MANUAL) ===");
//                            $this->logger?->warning("Owner table name: " . $metadata->getTableName());
//                            $this->logger?->warning("Owner identifier fields: " . implode(', ', $metadata->getIdentifierFieldNames()));
                        }
                    } else {
                        $failedCount++;
//                        $this->logger?->error("✗ getMetadataFor() returned null for: {$entityClass}");
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->logger?->error("✗ Exception loading metadata for {$entityClass}: " . $e->getMessage());
                    $this->logger?->error("Exception type: " . get_class($e));
                    if ($entityClass === Owner::class) {
                        $this->logger?->error("=== FAILED TO LOAD OWNER METADATA ===");
                        $this->logger?->error("Owner metadata loading failed with: " . $e->getMessage());
                    }
                }
            }

            $this->logger?->warning("Metadata loading summary: {$loadedCount} successful, {$failedCount} failed out of " . count($pixieEntityClasses) . " total");

            if ($loadedCount === 0) {
                $this->logger?->error("NO metadata could be loaded for any entity - this indicates a serious Doctrine configuration problem");
                throw new \RuntimeException("Could not load any entity metadata. Check Doctrine configuration and entity paths.");
            }
        }

        if (!$ownerMetadata) {
            $this->logger?->error("=== OWNER METADATA STILL NOT FOUND ===");
            throw new \RuntimeException("Could not load Owner entity metadata");
        } else {
            $this->logger?->warning("Owner metadata confirmed loaded");
        }

        $this->logger?->info("Total Pixie entity classes found: " . count($pixieClasses));
        foreach ($pixieClasses as $meta) {
            $this->logger?->info("Will create schema for: " . $meta->getName() . " -> " . $meta->getTableName());
        }

        // Create/align schema for Pixie entities
        if ($pixieClasses) {
            $this->logger?->warning("Creating schema with " . count($pixieClasses) . " entity classes");

            $tool = new SchemaTool($em);
            try {
                // 'saveMode' = true keeps existing tables, creates missing parts
                $sql = $tool->getCreateSchemaSql($pixieClasses);
                $this->logger?->info("Schema SQL to execute (" . count($sql) . " statements):");
                foreach ($sql as $i => $statement) {
                    $this->logger?->info("SQL {$i}: " . substr($statement, 0, 200) . (strlen($statement) > 200 ? '...' : ''));

                    // Look specifically for owner table creation
                    if (stripos($statement, 'owner') !== false) {
                        $this->logger?->warning("=== OWNER TABLE SQL FOUND ===");
                        $this->logger?->warning("Owner SQL: {$statement}");
                    }
                }

                $tool->updateSchema($pixieClasses, true);
                $this->logger?->warning("Schema creation completed successfully");

                // Verify owner table was created
                $tablesAfter = $sm->listTableNames();
                $this->logger?->info("Tables after schema creation: " . implode(', ', $tablesAfter));
                $this->logger?->warning("OWNER table created: " . (in_array('owner', $tablesAfter) ? 'YES' : 'NO'));

            } catch (\Exception $e) {
                $this->logger?->error("Schema creation failed: " . $e->getMessage());
                $this->logger?->error("Exception type: " . get_class($e));
                $this->logger?->error("Stack trace: " . $e->getTraceAsString());
                throw $e;
            }
        } else {
            $this->logger?->error("No Pixie entity classes found - cannot create schema");
            throw new \RuntimeException("No Pixie entity metadata found for schema creation");
        }

        $this->logger?->warning("=== ENSURE SCHEMA END ===");
    }

    /**
     * Check if the Owner entity file exists and is loadable
     */
    private function checkOwnerEntityFile(): void
    {
//        $this->logger?->warning("=== CHECKING OWNER ENTITY FILE ===");

        // Check if Owner class exists
        if (class_exists(Owner::class)) {
//            $this->logger?->warning("Owner class exists and is autoloadable");

            try {
                $reflection = new \ReflectionClass(Owner::class);
                $ownerFile = $reflection->getFileName();
//                $this->logger?->warning("Owner file location: {$ownerFile}");
//                $this->logger?->warning("Owner file exists: " . (file_exists($ownerFile) ? 'YES' : 'NO'));
//                $this->logger?->warning("Owner file readable: " . (is_readable($ownerFile) ? 'YES' : 'NO'));

                // Check for Doctrine annotations/attributes
                $attributes = $reflection->getAttributes(\Doctrine\ORM\Mapping\Entity::class);
//                $this->logger?->warning("Owner has Entity attribute: " . (count($attributes) > 0 ? 'YES' : 'NO'));

                if (count($attributes) > 0) {
                    $entityAttr = $attributes[0]->newInstance();
//                    $this->logger?->warning("Owner Entity repositoryClass: " . ($entityAttr->repositoryClass ?? 'null'));
                }

                // Check for Table attribute
//                $tableAttributes = $reflection->getAttributes(\Doctrine\ORM\Mapping\Table::class);
//                if (count($tableAttributes) > 0) {
//                    $tableAttr = $tableAttributes[0]->newInstance();
//                    $this->logger?->warning("Owner Table name: " . ($tableAttr->name ?? 'default'));
//                } else {
//                    $this->logger?->warning("Owner has no explicit Table attribute - will use class name");
//                }

            } catch (\ReflectionException $e) {
                $this->logger?->error("Could not reflect Owner class: " . $e->getMessage());
            }
        } else {
//            $this->logger?->error("Owner class does NOT exist or is not autoloadable!");

            // Try to find the file manually
            $possiblePaths = [
                $this->projectDir . '/vendor/survos/pixie-bundle/src/Entity/Owner.php',
                $this->projectDir . '/packages/pixie-bundle/src/Entity/Owner.php',
                dirname(__DIR__) . '/Entity/Owner.php',
            ];

//            foreach ($possiblePaths as $path) {
//                $this->logger?->warning("Checking path: {$path}");
//                $this->logger?->warning("Path exists: " . (file_exists($path) ? 'YES' : 'NO'));
//            }
        }

//        $this->logger?->warning("=== OWNER ENTITY FILE CHECK END ===");
    }

    /**
     * Get PixieBundle entity classes by scanning the actual entity directory
     */
    /**
     * Get PixieBundle entity classes by scanning the actual entity directory
     */
    private function getPixieEntityClasses(): array
    {
        $entityClasses = [];

        // Try multiple possible paths for the PixieBundle entities
        $possiblePaths = [
            $this->projectDir . '/vendor/survos/pixie-bundle/src/Entity',
            $this->projectDir . '/packages/pixie-bundle/src/Entity', // local development
            dirname(__DIR__) . '/Entity', // running from within bundle
        ];

        $this->logger?->debug("Scanning for PixieBundle entities in " . count($possiblePaths) . " possible paths");

        foreach ($possiblePaths as $entityDir) {
            $this->logger?->debug("Checking directory: {$entityDir}");

            if (!is_dir($entityDir)) {
                $this->logger?->debug("Directory does not exist: {$entityDir}");
                continue;
            }

            if (!is_readable($entityDir)) {
                $this->logger?->warning("Directory is not readable: {$entityDir}");
                continue;
            }

            try {
                $finder = new \Symfony\Component\Finder\Finder();
                $finder->files()->name('*.php')->in($entityDir);

                foreach ($finder as $file) {
                    // Build the correct namespace including subdirectories
                    $relativePath = $file->getRelativePathname(); // e.g., "Field/DatabaseField.php"
                    $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath); // "Field\\DatabaseField"
                    $className = 'Survos\\PixieBundle\\Entity\\' . $classPath; // "Survos\\PixieBundle\\Entity\\Field\\DatabaseField"

                    $basename = $file->getBasename('.php');

                    // Debug for Field entities specifically
                    if (str_contains($basename, 'Field')) {
                        $this->logger?->debug("=== FOUND {$basename} FILE DURING SCAN ===");
                        $this->logger?->debug("{$basename} file path: " . $file->getRealPath());
                        $this->logger?->debug("{$basename} relative path: {$relativePath}");
                        $this->logger?->debug("{$basename} className: {$className}");
                    }

                    // Skip known non-entity files
                    if (str_ends_with($basename, 'Interface') ||
                        str_ends_with($basename, 'Trait') ||
                        $basename === 'CoreEntity' || // Skip CoreEntity, use Core instead
                        str_contains($basename, 'Abstract')) {
                        $this->logger?->debug("Skipping non-entity file: {$className}");
                        continue;
                    }

                    // Verify the class exists and is loadable
                    if (class_exists($className)) {
                        // Additional check: see if it has Doctrine annotations/attributes
                        try {
                            $reflection = new \ReflectionClass($className);
                            $attributes = $reflection->getAttributes(\Doctrine\ORM\Mapping\Entity::class);
                            if (!empty($attributes) || !$reflection->isAbstract()) {
                                $entityClasses[] = $className;
                                $this->logger?->debug("Found entity class: {$className}");
                            } else {
                                $this->logger?->debug("Skipping class without Entity attribute: {$className}");
                            }
                        } catch (\ReflectionException $e) {
                            $this->logger?->debug("Could not reflect class {$className}: " . $e->getMessage());
                        }
                    } else {
                        $this->logger?->debug("Class does not exist or is not loadable: {$className}");
                    }
                }

                // If we found classes in this path, stop looking
                if (!empty($entityClasses)) {
                    $this->logger?->info("Found " . count($entityClasses) . " PixieBundle entities in: {$entityDir}");
                    break;
                }
            } catch (\Exception $e) {
                $this->logger?->error("Could not scan directory {$entityDir}: " . $e->getMessage());
                continue;
            }
        }

        if (empty($entityClasses)) {
            $searchedPaths = implode(', ', $possiblePaths);
            $this->logger?->error("No PixieBundle entity classes found in any of the searched paths: {$searchedPaths}");
            throw new \RuntimeException("Could not find PixieBundle entity classes. Searched paths: {$searchedPaths}");
        }

        $this->logger?->info("Successfully discovered " . count($entityClasses) . " PixieBundle entity classes");
        return $entityClasses;
    }
    /**
     * Get a PixieContext for the given pixie code
     */
    public function getReference(?string $pixieCode = null): PixieContext
    {
        if (!$pixieCode) {
            $pixieCode = $this->currentPixieCode;
            if (!$pixieCode) {
                throw new \RuntimeException('No pixie code provided and no current pixie code set');
            }
        }

        $this->logger?->info("=== getReference START ===");
        $this->logger?->info("getReference called for pixieCode: {$pixieCode}");

        // Switch to the correct database
        $em = $this->switchToPixieDatabase($pixieCode);
        $this->logger?->info("switchToPixieDatabase returned");

        // Ensure schema exists (this should be idempotent after migration)
        $this->logger?->info("Calling ensureSchema...");
        $this->ensureSchema($em);
        $this->logger?->info("ensureSchema completed");

        // Build config from YAML + any compiled schema
        $this->logger?->info("Building config snapshot...");
        $config = $this->buildConfigSnapshot($pixieCode, $em);
        $this->logger?->info("Config snapshot built");

        // Check if owner exists in this database
        $ownerRef = null;
        $conn = $em->getConnection();

//        $this->logger?->info("=== OWNER CHECK START ===");

        try {
            // First verify we can access the database
            $currentPath = $conn->getParams()['path'] ?? 'UNKNOWN';
//            $this->logger?->info("About to check owner in database: {$currentPath}");
//            $this->logger?->info("Connection is connected: " . ($conn->isConnected() ? 'YES' : 'NO'));

            // List all tables first
            $allTables = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table'")->fetchFirstColumn();
//            $this->logger?->warning("All tables in database: " . implode(', ', $allTables));

            // Check if owner table exists
            $tableExists = $conn->executeQuery(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='owner'"
            )->fetchOne();

//            $this->logger?->warning("Owner table query result: " . ($tableExists ?: 'NULL'));

            if (!$tableExists) {
                $this->logger?->error("OWNER TABLE DOES NOT EXIST in {$currentPath}");
                $this->logger?->error("Available tables: " . implode(', ', $allTables));
                $this->logger?->error("This will cause the 'no such table: owner' error");
            } else {
//                $this->logger?->warning("Owner table EXISTS - checking for record...");

                // Check if specific owner record exists
                $ownerExists = (bool)$conn->fetchOne('SELECT 1 FROM owner WHERE id = ?', [$pixieCode]);
//                $this->logger?->warning("Owner record with id '{$pixieCode}' exists: " . ($ownerExists ? 'YES' : 'NO'));

                if ($ownerExists) {
//                    $this->logger?->warning("Creating owner reference...");
                    $ownerRef = $em->getReference(Owner::class, $pixieCode);
//                    $this->logger?->warning("Owner reference created");
                } else {
                    $this->logger?->warning("Owner record does not exist - will need to be created");
                }
            }
        } catch (\Exception $e) {
//            $this->logger?->error("EXCEPTION during owner check: " . $e->getMessage());
//            $this->logger?->error("Exception class: " . get_class($e));
//            $this->logger?->error("Exception trace: " . $e->getTraceAsString());
            // Don't throw here - let the context be created without ownerRef
            // The calling code can handle creating the owner if needed
        }

//        $this->logger?->warning("=== OWNER CHECK END ===");
        $this->currentPixieCode = $pixieCode;
        $this->logger?->info("Creating PixieContext with ownerRef: " . ($ownerRef ? 'SET' : 'NULL'));
        $context = new PixieContext($pixieCode, $config, $em, $ownerRef);
        $this->logger?->info("=== getReference END ===");
        return $context;
    }

    /**
     * Build a pure, immutable Config snapshot for the current pixie,
     * using compiled schema (CoreDefinition/FieldDefinition) from the given EM.
     * If no compiled schema exists yet, fall back to the YAML tables unchanged.
     */
    private function buildConfigSnapshot(string $pixieCode, EntityManagerInterface $em): Config
    {
        // base copy from bundle YAML (labels, paths etc.)
        $yaml = $this->getConfigFiles(pixieCode: $pixieCode)[$pixieCode]
            ?? throw new \RuntimeException("Unknown pixie '$pixieCode'");

        $cfg = clone $yaml;
        $cfg->setOwner(null); // NEVER attach managed entities to Config
        $cfg->setPixieFilename($this->getPixieFilename($pixieCode));
        $cfg->dataDir = $this->resolveFilename($cfg->getSourceFilesDir(), 'data');

        // Try to read compiled schema (if any) - but don't fail if tables don't exist yet
        try {
            $coreDefs = $em->getRepository(CoreDefinition::class)
                ->findBy(['ownerCode' => $pixieCode], ['core' => 'ASC']);

            // If there is no compiled schema, keep YAML-provided tables intact
            if (!$coreDefs) {
                return $cfg; // << fallback to YAML
            }

            // Rebuild properties from compiled schema
            $tables = [];
            foreach ($coreDefs as $def) {
                $tName = $def->core;
                $pk = $def->pk;

                $fds = $em->getRepository(FieldDefinition::class)
                    ->findBy(['ownerCode' => $pixieCode, 'core' => $tName], ['position' => 'ASC', 'id' => 'ASC']);

                $props = [];
                foreach ($fds as $fd) {
                    $p = new Property($fd->code);
                    // Optionally derive flags from kind/target/delim:
                    // $p->setSubType($fd->getTargetCore());
                    $props[] = $p;
                }

                // keep the YAML table object but replace pk/properties
                $t = $cfg->getTable($tName);
                if ($t) {
//                    dd($t, $pk, $props);
                    $t->setPkName($pk);
                    $t->setProperties($props);
                    $tables[$tName] = $t;
                }
            }

            // Only override tables if we actually reconstructed at least one
            if ($tables) {
                $cfg->setTables($tables);
            }
        } catch (\Exception $e) {
            $this->logger?->info("Could not load compiled schema, using YAML: " . $e->getMessage());
            // Fall back to YAML config
        }

        return $cfg;
    }
}
