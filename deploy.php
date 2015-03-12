<?php
// Development error output
ini_set("display_errors", "1");
error_reporting(E_ALL);

// Set time limit to unlimited and ignore user aborts
set_time_limit(0);
ignore_user_abort(true);

// Receive payload and validate JSON
$payload = !empty($_POST['payload']) && json_decode($_POST['payload']) ? json_decode($_POST['payload'], true) : false;

// Set environment path variable for processes. This can be retrieved via SSH while logged in as same user as PHP script will execute as (such as www-data) via "echo $PATH"
$ENV_PATH = array(
    "PATH" => "/usr/local/rvm/gems/ruby-2.2.0/bin:/usr/local/rvm/gems/ruby-2.2.0@global/bin:/usr/local/rvm/rubies/ruby-2.2.0/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games:/usr/local/rvm/bin:/usr/local/lib/node_modules/grunt-cli/bin/grunt:/usr/local/bin/grunt:/usr/bin/npm"
);

/**
 * Run command on project
 *
 * @param string $project Project path
 * @param string $command The command to run (including any arguments)
 *
 * @return array Associative array (error status, status message)
 */
function runCommand($project, $command) {
    global $ENV_PATH;

    if (empty($project) || empty($command)) return array(true, "Project and command must be provided.");
    $cwd = "/var/www/html/$project";
    if(!is_dir($cwd)) mkdir($cwd, 0777, true);

    $descriptor = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
        2 => array("pipe", "a")
    );

    $process = proc_open("bash", $descriptor, $pipes, $cwd, $ENV_PATH);

    if (is_resource($process)) {
        // Send command over bash
        fwrite($pipes[0], $command);
        fclose($pipes[0]);

        // Get command response from shell
        $returnMsg = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Get errors, if any
        $returnErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // Get error code, if any
        $result = proc_close($process);

        // Handle result, sanely
        if ($result !== 0) {
            if (strlen(trim($returnMsg)) === 0) $result = 0; // No error message? No error.
        }

        return array($result, $returnMsg, $returnErr);
    } else {
        return array(true, "Unable to run bash process: $command");
    }
}

// Check if submitted repo is allowed to be used here
if ($payload !== false) {
    $config = parse_ini_file("deploy.ini", true);

    $repo = $payload['repository'];
    $owner = strtolower($repo['owner']);
    $slug = strtolower($repo['slug']);

    foreach ($config as $key => $project) {
        if (strtolower($project['owner']) == $owner && strtolower($project['slug']) == $slug) {
            $path = $project['path'];
            $branch = $project['branch'];

            // This path is used for all logging
            $logFile = "/var/www/html/_deploy_logs/{$slug}/" . date("Y-m-d_G-i-s") . ".log";
            if(!is_dir(dirname($logFile))) mkdir(dirname($logFile));
            $log = " >> $logFile 2>&1";
            $commands = array(
                // array(run as an initial install only, file dependency, command)

                // load profile
                array(false,  false, 'source /var/www/.bash_profile' . $log),

                // init repo, or reinit if exists
                array(false,  false, 'git init' . $log),

                // add origin if it doesn't exist
                array(false,  false, 'git remote add origin git@bitbucket.org:' . $owner . '/' . $slug . '.git' . $log),

                // added 9/26/14 just in case origin already exists but had changed
                array(false,  false, 'git remote set-url origin git@bitbucket.org:' . $owner . '/' . $slug . '.git' . $log),

                // fetch all branches
                array(false,  false, 'git fetch origin' . $log),

                // reset to HEAD of $branch
                array(false,  false, 'git reset --hard origin/' . $branch . $log),

                // install bundler if not exists
                array(true,  "Gemfile", 'gem install bundler' . $log),

                // update bundler files
                array(true,  "Gemfile", 'bundle update' . $log),

                // install gem dependencies if missing, uses Gemfile
                array(true,  "Gemfile", 'bundle install --path vendor/bundler' . $log),

                // install/update missing npm packages from gemfile
                array(true, "package.json", 'npm update' . $log),

                // install npm packages
                array(true, "package.json", 'npm install --dev' . $log),

                // run grunt deploy tasks
                array(false, "Gruntfile.js", 'grunt deploy' . $log),

                // create an installed file
                array(true,  false, 'touch .INSTALLED' . $log)
            );

            foreach ($commands as $command) {
                $initial    = $command[0];
                $fileCheck  = $command[1];
                $command    = $command[2];
                $commandOut = str_replace($log, "", $command);

                // don't run initialization commands
                if($initial && file_exists("/var/www/html/{$path}/.INSTALLED")) {
                    file_put_contents($logFile, "> SKIPPING $commandOut (.INSTALLED file exists)\r\n\r\n", FILE_APPEND);
                    continue;
                }

                // don't run on missing file dependencies
                if($fileCheck !== false && !file_exists("/var/www/html/{$path}/$fileCheck")) {
                    file_put_contents($logFile, "> SKIPPING $commandOut (Missing dependency file $fileCheck)\r\n\r\n", FILE_APPEND);
                    continue;
                }

                // run command
                file_put_contents($logFile, "> RUNNING COMMAND: $commandOut\r\n", FILE_APPEND);
                $result = runCommand($path, $command);
                file_put_contents($logFile, "> EXIT CODE: {$result[0]}\r\n\r\n", FILE_APPEND);
            }

            $log = file_get_contents($logFile);
            $log = iconv("UTF-8", "ISO-8859-1//TRANSLIT", $log);
            file_put_contents($logFile, $log);

            // output debug to browser
            $output  = "<pre>";
            $output .= file_get_contents($logFile);
            $output .= "\r\n\r\n";
            $output .= str_repeat("-", 50);
            $output .= "\r\n\r\n";
            $output .= var_export($payload, true);
            $output .= "</pre>";
            //echo $output;

            // email commit authors
            $authors = array();
            foreach ($payload['commits'] as $commit) $authors[] = $commit['raw_author'];
            $authors = array_unique($authors);
            $authors = implode(",", $authors);
            mail(
                $authors,
                "GIT Auto Deploy - $slug - build results",
                $output,
                "MIME-Version: 1.0\r\nContent-type: text/html; charset=iso-8859-1\r\nFrom: Build Server <me@build-server.com>\r\n\r\n"
            );

            exit; // stop loop
        }
    }

    // Send email if deployment failed (repo doesn't exist in deploy.ini)
    mail("some@person.com", "Failed GIT Auto Deploy", var_export($payload, true), "From: Build Server <me@build-server.com>\r\n");
}