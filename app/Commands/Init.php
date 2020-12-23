<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

class Init extends Command
{

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'init {project : The name of your Laravel project}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Initialise Laravel Buddy';

    private $project;

    private $directory;

    private $config_path;

    /**
     * @return mixed
     */
    public function getConfigPath()
    {
        return $this->config_path;
    }

    /**
     * Get path of config file
     */
    public function getConfigFile()
    {
        return $this->getConfigPath() . '/db-config.json';
    }

    /**
     */
    public function setConfigPath(): void
    {
        $this->config_path = getenv("HOME") . '/.laravel-buddy';
    }

    /**
     * Get project name
     *
     * @return null
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * Set project name
     *
     * @param null $project
     */
    public function setProject($project): void
    {
        $this->project = $project;
    }

    /**
     * set app directory
     *
     * @param $project
     */
    public function setDirectory($project)
    {
        $this->directory = base_path($project) . '/';
    }

    /**
     * get app directory
     *
     * @return mixed
     */
    public function getDirectory()
    {
        return $this->directory;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->task("Intitialising", function () {
            $this->setDirectory($this->argument('project'));
            $this->setProject($this->argument('project'));
            $this->setConfigPath();
        });

        $this->task("Installing Laravel", function () {
            // check if composer is installed. If not, install composer.
            $composer = $this->findComposer();

            if (!$composer) {
                $installComposer = $this->ask('Composer is missing from your system. Do you want to install Composer?');

                if ($installComposer) {
                    $this->installComposer();
                }
            }

            $this->info($this->installLaravel());
        });

        $this->task('Database setup', function () {
            $this->info("\nWhich database do you want to use?");

            $option = $this->menu('Project default database', [
                'mysql/mariadb',
                'pgsql',
                'sqlite',
                'sqlsrv',
            ])->open();

            $port = null;
            $host = null;
            $connection = null;

            switch ($option) {
            case 0:
                $this->info('You selected mysql/mariadb as default database for your project.');
                $port = 3306;
                $host = '127.0.0.1';
                $connection = 'mysql';

                break;
            case 1:
                $this->info('You selected postgresql as default database for your project.');
                $port = 5432;
                $host = '127.0.0.1';
                $connection = 'pgsql';

                break;
            case 2:
                $this->info('You selected sqlite as default database for your project.');
                $connection = 'sqlite';

                break;
            case 3:
                $this->info('You selected Microsoft sql server as default database for your project.');
                $port = 1433;
                $host = '127.0.0.1';
                $connection = 'sqlsrv';

                break;
        }

            if ($connection == 'sqlite') {
                file_put_contents($this->getDirectory() . '.env', preg_replace("/(DB_CONNECTION=.*)/", 'DB_CONNECTION=sqlite', file_get_contents($this->getDirectory()  . '.env')));
            }

            if ($connection != 'sqlite') {
                if (! is_file($this->getConfigFile())) {
                    if (! file_exists($this->getConfigPath())) {
                        mkdir($this->getConfigPath());
                    }

                    file_put_contents($this->getConfigFile(), "");
                }

                if (is_file($this->getConfigFile())) {
                    if (strpos(file_get_contents($this->getConfigFile()), $connection)) {
                        $this->info('Getting credentials from config file...');

                        $config = file_get_contents($this->getConfigFile());

                        $config_array = json_decode($config, true);

                        $db_array = [
                            $connection => $config_array[$connection]
                        ];

                        $this->setDatabaseCredentials($db_array);
                    }

                    if (! strpos(file_get_contents($this->getConfigFile()), $connection)) {
                        $database = $this->ask('Please enter database name');
                        $db_username = $this->ask('Please enter your database username');
                        $db_password = $this->ask('Please enter your database password');

                        $db_array = [
                            $connection => [
                                'DB_CONNECTION' => $connection,
                                'DB_PORT' => $port,
                                'DB_HOST' => $host,
                                'DB_DATABASE' => $database,
                                'DB_USERNAME' => $db_username,
                                'DB_PASSWORD' => $db_password
                            ]
                        ];

                        $this->setDatabaseCredentials($db_array);
                    }
                }
            }
        });
    }

    /**
     * Set db credentials
     *
     * @param array $dbInfo
     */
    protected function setDatabaseCredentials(array $dbInfo)
    {
        $connection = collect($dbInfo)->first()['DB_CONNECTION'];

        chdir($this->getProject());

        $this->writeToEnv($dbInfo[$connection]);

        $this->storeDatabaseInfo($dbInfo);

        $this->info('Project setup complete!');

        $this->notify('Laravel Buddy', 'Your Laravel project setup is complete!');
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composer_path = getcwd() . '/composer.phar';

        if (file_exists($composer_path)) {
            return '"' . PHP_BINARY . '" ' . $composer_path;
        }

        return 'composer';
    }

    /**
     * @param $db_info
     */
    protected function storeDatabaseInfo(array $db_info)
    {
        $connection = collect($db_info)->first()['DB_CONNECTION'];

        if (filesize($this->getConfigFile()) == 0) {
            file_put_contents($this->getConfigFile(), json_encode($db_info, JSON_FORCE_OBJECT));
        }

        if (filesize($this->getConfigFile()) != 0) {
            if (strpos(file_get_contents($this->getConfigFile()), $connection)) {
                return;
            }

            $config = file_get_contents($this->getConfigFile());

            $temp_config = (array) json_decode($config, true);

            $newConfig = array_merge($temp_config, $db_info);

            file_put_contents($this->getConfigFile(), json_encode($newConfig, JSON_FORCE_OBJECT));
        }
    }

    /**
     * Install composer when it's missing from system
     */
    protected function installComposer()
    {
        $composer_script_path = base_path() . 'composer-installer';

        $this->info(shell_exec("php {$composer_script_path}"));
    }

    /**
     * Install Laravel framework
     */
    protected function installLaravel()
    {
        $install_laravel = $this->findComposer() . " create-project laravel/laravel {$this->argument('project')}";

        $this->info(shell_exec($install_laravel));
    }

    /**
     * Set Database fields in env file
     *
     * @param $dbInfo
     */
    protected function writeToEnv($dbInfo): void
    {
        $env_file = file_exists('/.env');

        if (! $env_file) {
            copy('.env.example', '.env');
        }

        foreach ($dbInfo as $key => $value) {
            $pattern = "/({$key}=.*)/";

            $replace = "$key={$value}";

//            file_put_contents($this->getDirectory() . '.env', preg_replace($pattern, $replace, file_get_contents('.env')));
            File::put('.env', preg_replace($pattern, $replace, File::get('.env')));
        }
    }
}
