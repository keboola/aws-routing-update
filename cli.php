<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateRouteTables extends \Symfony\Component\Console\Command\Command
{
    protected function configure()
    {
        $this
            ->setName('aws:update-route-tables')
            ->setDescription('Add new routes to route tables')
            ->addArgument('region', InputArgument::REQUIRED, 'aws region')
            ->addArgument('route-tables', InputArgument::REQUIRED, 'comma separated list of route tables to apply')
            ->addArgument('target-eni-id', InputArgument::REQUIRED, 'target network interface id')
            ->addArgument('source-file', InputArgument::OPTIONAL, 'source file', './routes.json')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new \Aws\Ec2\Ec2Client([
            'region' => $input->getArgument('region'),
            'version' => '2016-11-15',
            'debug' => false,
        ]);

        $routes = json_decode(file_get_contents($input->getArgument('source-file')));
        $output->writeln(sprintf(
            "%d routes fetch from file %s",
            count($routes),
            $input->getArgument('source-file')
        ));

        $routeTables = explode(',', $input->getArgument('route-tables'));
        $output->writeln('Ready to update route tables:');
        foreach ($routeTables as $routeTable) {
            $output->writeln(" - {$routeTable}");
        }
        $output->writeln("");

        $targetNetworkInterfaceId = $input->getArgument('target-eni-id');
        $output->writeln('Target network interface:');
        $output->writeln(sprintf(" - %s", $targetNetworkInterfaceId));
        $output->writeln("");

        foreach ($routeTables as $routeTable) {
            $output->writeln("Updating route table $routeTable");
            foreach ($routes as $route) {
                $output->writeln(sprintf(" - Adding route %s -> %s", $route->DestinationCidrBlock, $targetNetworkInterfaceId));

                $client->createRoute([
                    'RouteTableId' => $routeTable,
                    'DestinationCidrBlock' => $route->DestinationCidrBlock,
                    'NetworkInterfaceId' => $targetNetworkInterfaceId,
                ]);
            }

        }

    }
}

$app = new \Symfony\Component\Console\Application();
$app->add(new UpdateRouteTables());
$app->run();