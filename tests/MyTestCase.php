<?php

use PHPUnit\Framework\TestCase;

class MyTestCase extends TestCase {
	protected $subject;

	protected static $dbh;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('APP_DB_HOST');
        $db = getenv('APP_DB_DB');
        $user = getenv('APP_DB_USER');
        $password = getenv('APP_DB_PASSWORD');

        try {
            //self::$dbh = new \PDO('sqlite::memory:');
            self::$dbh = new \PDO("mysql:host=$host;dbname=$db", $user, $password);

            self::$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            throw $e;
        }
        
        self::createTables();
    }

    public static function tearDownAfterClass(): void
    {
        self::$dbh = null;
    }
    
    public static function createTables() {
        $commands[] = <<<EOD
        CREATE TABLE IF NOT EXISTS `requests`(
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `alias` VARCHAR(255) NULL,
            `vendor_profiles` VARCHAR(255) NOT NULL,
            `expires_in` INTEGER NOT NULL,
            `amount` BIGINT NOT NULL,
            `currency` VARCHAR(255) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(`id`)
        );
EOD;

        $commands[] = <<<EOD
        CREATE TABLE IF NOT EXISTS `states`(
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `state` VARCHAR (255) NOT NULL,
            `parameters` TEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `request_id` BIGINT NOT NULL,
            PRIMARY KEY(`id`),
            INDEX `req_id` (`request_id`),
            FOREIGN KEY (`request_id`) REFERENCES `requests`(`id`) ON UPDATE CASCADE ON DELETE CASCADE
        );
EOD;

        foreach ($commands as $command) {
            try {
                self::$dbh->exec($command);
            } catch(\Exception $excp) {
                //fwrite(STDOUT, print_r(['command'=>$command, 'error'=>$excp->getMessage()], true));
                throw $excp;
            }
        }
    }
    
    public function toConsole($message) {
        fwrite(STDOUT, PHP_EOL . $message . PHP_EOL);
    }   
}