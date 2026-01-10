<?php

declare(strict_types=1);

namespace Tests\Domain\Router;

use App\Domain\Router\RouterService;
use App\Domain\Participant\Participant;
use App\Application\Settings\SettingsInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;
use Prophecy\Argument;

class RouterTest extends TestCase
{
    private $routerService;
    private $settingsProphecy;
    private $loggerProphecy;

    protected function setUp(): void
    {
        // This boots external dependencies like Eloquent
        $app = $this->getAppInstance();
        $app->getContainer()->get(\Illuminate\Database\Capsule\Manager::class);

        // Create table in memory
        \Illuminate\Database\Capsule\Manager::schema()->dropIfExists('participants_routes');
        \Illuminate\Database\Capsule\Manager::schema()->create('participants_routes', function ($table) {
            $table->id();
            $table->string('experiment_id');
            $table->string('participant_id');
            $table->string('condition_group')->nullable();
            $table->integer('current_step_index')->default(0);
            $table->string('status');
            $table->dateTime('last_heartbeat')->nullable();
            $table->text('properties')->nullable();
            $table->timestamps();
        });

        // Create mocks
        $this->settingsProphecy = $this->prophesize(SettingsInterface::class);
        $this->loggerProphecy = $this->prophesize(LoggerInterface::class);
    }

    private function createService()
    {
        return new RouterService(
            $this->settingsProphecy->reveal(),
            $this->loggerProphecy->reveal()
        );
    }

    public function testAssignNewParticipant()
    {
        $this->settingsProphecy->get('db_table')->willReturn('participants_routes');

        $config = [
            'access_control' => [], // No restrictions
            'assignment_strategy' => 'minimum',
            'heartbeat_intervalsec' => 300,
            'fallback_url' => 'http://fallback.com',
            'groups' => [
                'A' => ['limit' => 10, 'steps' => ['http://step1.com']],
                'B' => ['limit' => 10, 'steps' => ['http://step1b.com']]
            ]
        ];

        $experiments = [
            'exp_1' => [
                'enable' => true,
                'config' => $config
            ]
        ];

        $this->settingsProphecy->get('experiments')->willReturn($experiments);

        $service = $this->createService();
        $result = $service->assign('exp_1', 'participant_1', []);

        $this->assertEquals(200, $result['statusCode']);
        $this->assertEquals('ok', $result['data']['status']);
        $this->assertNotNull($result['data']['url']);

        // Verify DB
        $participant = Participant::where('participant_id', 'participant_1')->first();
        $this->assertNotNull($participant);
        $this->assertEquals('exp_1', $participant->experiment_id);
    }

    public function testAccessControlDeny()
    {
        $this->settingsProphecy->get('db_table')->willReturn('participants_routes');

        $config = [
            'access_control' => [
                'condition' => [
                    'type' => 'regex',
                    'field' => 'age',
                    'pattern' => '^2[0-9]$' // 20s only
                ],
                'deny_redirect' => 'http://denied.com'
            ],
            'assignment_strategy' => 'minimum',
            'groups' => ['A' => ['limit' => 10, 'steps' => ['http://step1.com']]]
        ];

        $experiments = [
            'exp_1' => [
                'enable' => true,
                'config' => $config
            ]
        ];

        $this->settingsProphecy->get('experiments')->willReturn($experiments);

        $service = $this->createService();

        // 30 years old -> Deny
        $result = $service->assign('exp_1', 'participant_deny', ['age' => 30]);
        $this->assertEquals(200, $result['statusCode']);
        $this->assertEquals('ok', $result['data']['status']);
        $this->assertEquals('Access denied', $result['data']['message']);
        $this->assertEquals('http://denied.com', $result['data']['url']);

        // 25 years old -> Allow
        $result2 = $service->assign('exp_1', 'participant_allow', ['age' => 25]);
        $this->assertEquals(200, $result2['statusCode']);
        $this->assertEquals('ok', $result2['data']['status']);
    }
}
