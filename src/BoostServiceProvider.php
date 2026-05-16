<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost;

use think\Service;
use Yangmingzhi\ThinkphpBoost\Commands\AddSkillCommand;
use Yangmingzhi\ThinkphpBoost\Commands\InstallCommand;
use Yangmingzhi\ThinkphpBoost\Commands\ListSkillsCommand;
use Yangmingzhi\ThinkphpBoost\Commands\ServeCommand;
use Yangmingzhi\ThinkphpBoost\Commands\UpdateCommand;

class BoostServiceProvider extends Service
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->commands([
            'boost:install'     => InstallCommand::class,
            'boost:serve'       => ServeCommand::class,
            'boost:update'      => UpdateCommand::class,
            'boost:add-skill'   => AddSkillCommand::class,
            'boost:list-skills' => ListSkillsCommand::class,
        ]);
    }
}
