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
            ->setName('update-route-tables')
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

class FindLegacyRouting extends \Symfony\Component\Console\Command\Command
{
    protected function configure()
    {
        $this
            ->setName('find-legacy-routing')
            ->setDescription('Find all configuration which are uusing legacy routing')
            ->addArgument('routes-source-file', InputArgument::OPTIONAL, 'source file', './routes.json')
            ->addArgument('hosts-source-file', InputArgument::OPTIONAL, 'source file', './hosts.csv')
            ->addArgument('output-file', InputArgument::OPTIONAL, 'source file', './matches.csv')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $routes = json_decode(file_get_contents($input->getArgument('routes-source-file')));
        $output->writeln(sprintf(
            "%d routes fetch from file %s",
            count($routes),
            $input->getArgument('routes-source-file')
        ));

        $hosts =  iterator_to_array(new \Keboola\Csv\CsvFile($input->getArgument('hosts-source-file')));
        array_shift($hosts);

        $matches = [];
        foreach ($hosts as $host) {
            $matches[] = $this->processHost($output, $host[0], $host[1], $host[2], $host[3], $routes);
        }
        $matchesFiltered = array_filter($matches);

        // write output to CSV file
        $outputFile = new \Keboola\Csv\CsvFile($input->getArgument('output-file'));
        $outputFile->writeRow([
            'project_id',
            'component',
            'config',
            'host',
            'matchedRoutes',
        ]);
        foreach ($matchesFiltered as $match) {
            $outputFile->writeRow($match);
        }
        $output->writeln(sprintf("Matches saved to %s", $input->getArgument('output-file')));
    }

    private function processHost(OutputInterface $output, $projectId, $componentId, $configId, $host, array $routes)
    {
        $output->writeln(sprintf(
            "Processing project %d, component %s, config %s, host %s",
            $projectId,
            $componentId,
            $configId,
            $host
        ));
        $ip = gethostbyname($host);
        $output->writeln(sprintf(" Hostname %s -> %s", $host, $ip));

        $matchedRules = array_filter($routes, function($route) use($ip) {
           return self::ipCIDRCheck($ip, $route->DestinationCidrBlock);
        });

        if (empty($matchedRules)) {
            $output->writeln(" No fixed IP routing found");
            return false;
        } else {
            $output->writeln(sprintf(
                " Matches found: %s",
                implode(" , ", array_map(function($route) {
                    return $route->DestinationCidrBlock;
                }, $matchedRules))
            ));
            return [
                'project_id' => $projectId,
                'component' => $componentId,
                'config' => $configId,
                'host' => $host,
                'routingRules' => implode(" , ", array_map(function($route) {
                    return $route->DestinationCidrBlock;
                }, $matchedRules))
            ];
        }

    }

    private static function ipCIDRCheck ($IP, $CIDR) {
        list ($net, $mask) = explode("/", $CIDR);

        $ip_net = ip2long ($net);
        $ip_mask = ~((1 << (32 - $mask)) - 1);

        $ip_ip = ip2long ($IP);

        $ip_ip_net = $ip_ip & $ip_mask;

        return ($ip_ip_net === $ip_net);
    }
}

$app = new \Symfony\Component\Console\Application();
$app->add(new UpdateRouteTables());
$app->add(new FindLegacyRouting());
$app->run();