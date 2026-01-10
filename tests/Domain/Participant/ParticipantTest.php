<?php

declare(strict_types=1);

namespace Tests\Domain\Participant;

use App\Domain\Participant\Participant;
use Tests\TestCase;

class ParticipantTest extends TestCase
{
    public function testParticipantCreation()
    {
        $participant = new Participant([
            'experiment_id' => 'exp_1',
            'browser_id' => 'browser_1',
            'condition_group' => 'A',
            'status' => 'assigned'
        ]);

        $this->assertEquals('exp_1', $participant->experiment_id);
        $this->assertEquals('browser_1', $participant->browser_id);
        $this->assertEquals('A', $participant->condition_group);
        $this->assertEquals('assigned', $participant->status);
    }

    public function testJsonSerialization()
    {
        $participant = new Participant([
            'experiment_id' => 'exp_1',
            'browser_id' => 'browser_1',
            'condition_group' => 'A',
            'status' => 'assigned',
            'properties' => ['age' => 25]
        ]);

        $json = json_encode($participant);
        $data = json_decode($json, true);

        $this->assertEquals('exp_1', $data['experiment_id']);
        $this->assertEquals('browser_1', $data['browser_id']);
         // Note: Accessors might be needed or key mapping depending on Model, 
         // but Eloquent usually serializes attributes directly.
    }
}
