<?php

namespace terra\Command\Environment;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Question\ChoiceQuestion;
use terra\Command\Command;
use terra\Factory\EnvironmentFactory;

class EnvironmentEnable extends Command
{
    protected function configure()
    {
        $this
        ->setName('environment:enable')
        ->setDescription('Enable environment.')
        ->addArgument(
            'app_name',
            InputArgument::OPTIONAL,
            'The name the app to enable.'
        )
        ->addArgument(
            'environment_name',
            InputArgument::OPTIONAL,
            'The name the environment to enable.'
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ask for an app and environment.
        $this->getApp($input, $output);
        $this->getEnvironment($input, $output);

        $environment_name = $this->environment->name;
        $app_name = $this->app->name;

        // Attempt to enable the environment.
        $environment_factory = new EnvironmentFactory($this->environment, $this->app);
        if (!$environment_factory->enable()) {
            $output->writeln('<error>Something went wrong, environment not enabled.</error>');

            return;
        }

        // Get new port, set new URL to environment object.
        $port = $environment_factory->getPort();
        $host = $environment_factory->getHost();
        $this->environment->url = "http://$host:$port";

        // When passing to saveEnvironment, it must have app and name properties (for now).
        $this->environment->app = $app_name;
        $this->environment->name = $environment_name;

        // Save environment metadata.
        $this->getApplication()->getTerra()->getConfig()->saveEnvironment($this->environment);
        if ($this->getApplication()->getTerra()->getConfig()->save()) {
            $output->writeln('<info>Environment enabled!</info>  Available at http://'.$environment_factory->getUrl().' and ' . $this->environment->url);
        } else {
            $output->writeln('<error>Environment info not saved.</error>');
        }

        // Write drush alias.
        $drush_alias_file_path = "{$_SERVER['HOME']}/.drush/{$app_name}.aliases.drushrc.php";
        if ($environment_factory->writeDrushAlias()) {
            $output->writeln("<info>Drush alias file created at {$drush_alias_file_path}</info>");
            $output->writeln("Wrote drush alias file to <comment>$drush_alias_file_path</comment>");
            $output->writeln("Use <info>drush @{$app_name}.{$environment_name}</info> to access the site.");
        } else {
            $output->writeln('<error>Unable to save drush alias.</error>');
        }

        // Run the enable hooks
        $output->writeln('');
        $output->writeln('Running <comment>ENABLE</comment> app hook...');

        $environment_factory->getConfig();
        if (!empty($environment_factory->config['hooks']['enable'])) {
            // Output what we are running
            $formatter = $this->getHelper('formatter');
            $errorMessages = array($environment_factory->config['hooks']['enable']);
            $formattedBlock = $formatter->formatBlock($errorMessages, 'question');
            $output->writeln($formattedBlock);

            chdir($environment_factory->getSourcePath());
            $process = new Process($environment_factory->config['hooks']['enable']);
            $process->setTimeout(null);
            $process->run(function ($type, $buffer) {
                if (Process::ERR === $type) {
                    echo $buffer;
                } else {
                    echo $buffer;
                }
            });
        }
    }
}
