<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_toggle_and_delete_a_branch_announcement(): void
    {
        [$cafe, $branch, $owner] = $this->ownerWithBranch();

        $this->actingAs($owner)->post(route('manage.announcements.store'), [
            'title' => 'Cuma akşamı canlı müzik',
            'body' => 'Saat 21.00’de sahnedeyiz.',
            'type' => Announcement::TYPE_EVENT,
            'branch_id' => $branch->id,
            'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'is_active' => '1',
        ])->assertRedirect(route('manage.announcements.index'))->assertSessionHasNoErrors();

        $announcement = Announcement::query()->firstOrFail();
        $this->assertSame($cafe->id, $announcement->cafe_id);
        $this->assertSame($branch->id, $announcement->branch_id);
        $this->assertSame($owner->id, $announcement->created_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.created', 'subject_id' => $announcement->id]);

        $this->actingAs($owner)->get(route('manage.announcements.index'))
            ->assertOk()
            ->assertSee('Cuma akşamı canlı müzik')
            ->assertSee('Yayında');

        $this->actingAs($owner)->patch(route('manage.announcements.update', $announcement), [
            'title' => 'Cuma akşamı akustik',
            'body' => 'Saat 21.30’da sahnedeyiz.',
            'type' => Announcement::TYPE_EVENT,
            'branch_id' => '',
            'is_active' => '1',
        ])->assertRedirect(route('manage.announcements.index'))->assertSessionHasNoErrors();

        $announcement->refresh();
        $this->assertSame('Cuma akşamı akustik', $announcement->title);
        $this->assertNull($announcement->branch_id);

        $this->actingAs($owner)->patch(route('manage.announcements.toggle', $announcement))
            ->assertRedirect(route('manage.announcements.index'));
        $this->assertFalse($announcement->fresh()->is_active);

        $this->actingAs($owner)->delete(route('manage.announcements.destroy', $announcement))
            ->assertRedirect(route('manage.announcements.index'));
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_owner_cannot_target_another_cafes_branch(): void
    {
        [, , $owner] = $this->ownerWithBranch();
        $otherCafe = Cafe::query()->create(['name' => 'Diğer Kafe', 'slug' => 'diger-kafe', 'status' => Cafe::STATUS_ACTIVE]);
        $otherBranch = $otherCafe->branches()->create(['name' => 'Diğer Şube', 'slug' => 'diger-sube', 'is_active' => true]);

        $this->actingAs($owner)->post(route('manage.announcements.store'), [
            'title' => 'Yetkisiz hedef',
            'type' => Announcement::TYPE_ANNOUNCEMENT,
            'branch_id' => $otherBranch->id,
            'is_active' => '1',
        ])->assertSessionHasErrors('branch_id');

        $this->assertDatabaseCount('announcements', 0);
    }

    /** @return array{Cafe, Branch, User} */
    private function ownerWithBranch(): array
    {
        $cafe = Cafe::query()->create(['name' => 'Sahip Kafe', 'slug' => 'sahip-kafe', 'status' => Cafe::STATUS_ACTIVE]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);
        $owner = User::factory()->create([
            'role' => User::ROLE_CAFE_OWNER,
            'cafe_id' => $cafe->id,
        ]);

        return [$cafe, $branch, $owner];
    }
}
