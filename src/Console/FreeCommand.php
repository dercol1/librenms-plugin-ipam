<?php

namespace Dercol1\LibrenmsIpam\Console;

use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Web\FreeAddressFinder;
use Illuminate\Console\Command;

/**
 * Where the free addresses are in a range, from the command line.
 *
 * Same answer as the panel on the addresses page, for the cases where the
 * shell is where the work happens: a script handing out the next address, or
 * an operator who already has a terminal open.
 *
 * Hidden addresses count as used. They are configured on devices — loopback,
 * link-local — and suggesting one of those as free is the one way this tool
 * can hand out an address that is live.
 */
class FreeCommand extends Command
{
    protected $signature = 'ipam:free
        {cidr : the range to look inside, e.g. 10.14.146.0/24}
        {--suggest=10 : how many concrete free addresses to list}';

    protected $description = 'List the free addresses inside a range';

    public function handle(): int
    {
        if (SchemaInstaller::missing() !== []) {
            $this->error('The ipam tables are not installed, run: lnms ipam:install');

            return self::FAILURE;
        }

        $cidr = Cidr::parse((string) $this->argument('cidr'), true);

        if ($cidr === null) {
            $this->error('That is not a usable range: ' . $this->argument('cidr'));

            return self::FAILURE;
        }

        $result = (new FreeAddressFinder)->find($cidr, max(1, (int) $this->option('suggest')));

        $this->info(sprintf(
            '%s: %d in use, %d free.',
            $cidr->cidr(),
            $result['used_count'],
            $result['free_count'],
        ));

        if ($result['suggestions'] !== []) {
            $this->newLine();
            $this->line('Free addresses to hand out:');

            foreach ($result['suggestions'] as $address) {
                $this->line("  $address");
            }
        }

        if ($result['gaps'] !== []) {
            $this->newLine();
            $this->table(['from', 'to', 'free'], array_map(
                fn (array $gap): array => [$gap['start'], $gap['end'], $gap['count']],
                $result['gaps']
            ));
        }

        return self::SUCCESS;
    }
}
