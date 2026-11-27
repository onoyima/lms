<?php

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Each person arranges the cards on a page for themselves: order, hidden, minimised, full width. */
class ArrangementTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_a_page_arrangement_is_saved_per_person_and_shared_with_every_page(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer)->put('/arrangement/courses', [
            'order' => ['12', '7', '3'], 'hidden' => ['7'], 'collapsed' => ['3'], 'wide' => ['12'],
        ])->assertRedirect();

        $this->assertSame(['12', '7', '3'], $this->officer->fresh()->preferences['layouts']['courses']['order']);
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('layouts.courses.hidden', ['7'])->where('layouts.courses.collapsed', ['3']));

        // the dashboard's own arrangement is kept where the dashboard has always read it
        $this->put('/arrangement/dashboard', ['order' => ['glance', 'day'], 'hidden' => ['modules']])->assertRedirect();
        $this->assertSame(['modules'], $this->officer->fresh()->preferences['dashboard']['hidden']);

        // page names and card keys are checked
        $this->put('/arrangement/../etc', ['order' => ['x']])->assertNotFound();
        $this->put('/arrangement/courses', ['order' => ['<script>']])->assertSessionHasErrors('order.0');
    }
}
