<?php

namespace terra\Command\Environment;

use terra\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use terra\Factory\EnvironmentFactory;

// ...

class EnvironmentAdd extends Command
{
    protected function configure()
    {
        $this
        ->setName('environment:add')
        ->setDescription('Adds a new environment.')
        ->addArgument(
            'app_name',
            InputArgument::OPTIONAL,
            'The app you would like to add an environment for.'
        )
        ->addArgument(
            'environment_name',
            InputArgument::OPTIONAL,
            'The name of the environment.'
        )
        ->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'The path to the environment.'
        )
        ->addArgument(
            'branch',
            InputArgument::OPTIONAL,
            'The repo branch used to create the environment.'
        )
        ->addArgument(
            'document_root',
            InputArgument::OPTIONAL,
            'The path to the web document root within the repository.',
            '/'
        )
        ->addOption(
            'enable',
            '',
            InputArgument::OPTIONAL,
            'Enable this environment immediately.'
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ask for an app.
        $helper = $this->getHelper('question');
        $this->getApp($input, $output);

        // Ask for environment name
        $environment_name = $input->getArgument('environment_name');
        while (empty($environment_name) || isset($this->app->environments[$environment_name]) || !preg_match('/^[a-zA-Z0-9]+$/', $environment_name)) {
            $question = new Question('Environment name? ');
            $environment_name = $helper->ask($input, $output, $question);

            if(!preg_match('/^[a-zA-Z0-9]+$/', $environment_name)) {
              $output->writeln("<error> ERROR </error> Environment <comment>{$environment_name}</comment> cannot contain spaces or special characters.");
            }
            // Look for environment with this name
            if (isset($this->app->environments[$environment_name])) {
                $output->writeln("<error> ERROR </error> Environment <comment>{$environment_name}</comment> already exists in app <comment>{$this->app->name}</comment>");
            }
        }

        // Path
        $path = $input->getArgument('path');
        if (empty($path)) {
            // Load apps base path from Config.
            $config_path = $this->getApplication()->getTerra()->getConfig()->get('apps_basepath');

            // If it already exists, use "realpath" to load it.
            if (file_exists($config_path)) {
              $default_path = realpath($config_path).'/'.$this->app->name.'/'.$environment_name;
            }
            // If it doesn't exist, just use ~/Apps/$ENV as the default path.
            else {

              // Offer to create the apps path.
              $question = new ConfirmationQuestion("Create default apps path at $config_path? [y\N] ", false);
              if ($helper->ask($input, $output, $question)) {
                mkdir($config_path);
                $default_path = $_SERVER['HOME'] . '/Apps/' . $this->app->name . '/' . $environment_name;
              }
            }
            $question = new Question("Path: ($default_path) ", $default_path);
            $path = $helper->ask($input, $output, $question);
            if (empty($path)) {
                $path = $default_path;
            }
        }

        // Check for path
        $fs = new Filesystem();
        if (!$fs->isAbsolutePath($path)) {
            $path = getcwd().'/'.$path;
        }

        $branch_name = $input->getArgument('branch');
        while (empty($branch_name)) {
          $output->writeln("<info>Getting the default branch for <comment>{$this->app->repo}</comment> </info>");
          // command to get default branch
          $process = new Process("git ls-remote " . $this->app->repo . " | awk '{if (a[$1]) { print $2 } a[$1] = $2}' | grep heads | awk -F\"/\" '{print $3 }'");
          try {
            $process->mustRun();
          } catch (ProcessFailedException $e) {
            $output->writeln("<error> ERROR </error> Unable to find default git branch. <comment>{$e->getMessage()}</comment>");
          }
          $default_branch = trim($process->getOutput());
          $question = new Question("Version? [$default_branch]", $default_branch);
          $branch_name = $helper->ask($input, $output, $question);

          // Check if the remote branch exists
          if ($branch_name) {
            $output->writeln("<info>Checking if branch <comment>{$branch_name}</comment> exists in <comment>{$this->app->repo}</comment> </info>");
            $process = new Process('git ls-remote ' . $this->app->repo . ' | grep -sw "' . $branch_name . '"');
            $process->run();
            if (!$process->isSuccessful()) {
              $output->writeln("<error> ERROR </error> Branch <comment>{$branch_name}</comment> not found in repote repo <comment>{$this->app->repo}</comment>");
              return;
            }
          }
        }

        // Environment object
        $environment = array(
          'app' => $this->app->name,
          'name' => $environment_name,
          'path' => $path,
          'document_root' => '',
          'url' => '',
          'version' => $branch_name
        );

        // Prepare the environment factory.
        // Clone the apps source code to the desired path.
        $environmentFactory = new EnvironmentFactory($environment, $this->app);

        // Save environment to config.
        if ($environmentFactory->init($path)) {
            // Load config from file.
            $environmentFactory->getConfig();
            $environment['document_root'] = isset($environmentFactory->config['document_root']) ? $environmentFactory->config['document_root'] : '';

            // Save current branch
            $environment['version'] = $environmentFactory->getRepo()->getCurrentBranch();

            // Save to registry.
            $this->getApplication()->getTerra()->getConfig()->saveEnvironment($environment);
            $this->getApplication()->getTerra()->getConfig()->save();

            $output->writeln('<info>Environment saved to registry.</info>');
        } else {
            $output->writeln('<error>Unable to clone repository. Check app settings and try again.</error>');

            return;
        }

        // Offer to enable the environment
        $question = new ConfirmationQuestion("Enable this environment? [y\N] ", false);
        if ($input->getOption('enable') || $helper->ask($input, $output, $question)) {
            // Run environment:add command.
            $command = $this->getApplication()->find('environment:enable');
            $arguments = array(
              'app_name' => $this->app->name,
              'environment_name' => $environment_name
            );
            $input = new ArrayInput($arguments);
            $command->run($input, $output);
        }
    }
}
