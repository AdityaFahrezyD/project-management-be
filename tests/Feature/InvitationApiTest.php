<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\Notification;

class InvitationApiTest extends ApiTestCase
{
    public function test_invitation_is_hashed_email_scoped_and_single_use(): void
    {
        Notification::fake();
        $invitee = User::factory()->create();
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/invitations';
        $response = $this->postJson($url, ['email' => $invitee->email, 'role' => 'member'])->assertCreated()->assertJsonMissingPath('data.token_hash');
        $token = $this->invitationToken();
        $invitation = WorkspaceInvitation::findOrFail($response->json('data.id'));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden();
        $this->actingAs($invitee, 'web')->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertConflict();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $this->workspace->id, 'user_id' => $invitee->id, 'role' => 'member']);
        $this->getJson($this->projectUrl())->assertNotFound();
    }

    public function test_resend_rotates_token_and_expired_or_revoked_invitation_cannot_be_used(): void
    {
        Notification::fake();
        $invitee = User::factory()->create();
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/invitations';
        $id = $this->postJson($url, ['email' => $invitee->email, 'role' => 'member'])->assertCreated()->json('data.id');
        $oldToken = $this->invitationToken();
        Notification::fake();
        $this->postJson($url.'/'.$id.'/resend')->assertOk();
        $token = $this->invitationToken();
        $this->actingAs($invitee, 'web')->postJson('/api/v1/invitations/accept', ['token' => $oldToken])->assertNotFound();
        WorkspaceInvitation::findOrFail($id)->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertConflict();
        $this->actingAs($this->manager, 'web')->deleteJson($url.'/'.$id)->assertNoContent();
        $this->actingAs($invitee, 'web')->postJson('/api/v1/invitations/accept', ['token' => $token])->assertConflict();
    }

    public function test_admin_cannot_invite_admin_or_modify_another_admin(): void
    {
        $admin = $this->member();
        $this->workspace->memberships()->where('user_id', $admin->id)->update(['role' => 'admin']);
        $this->actingAs($admin, 'web');
        $this->postJson('/api/v1/workspaces/'.$this->workspace->id.'/invitations', ['email' => 'admin@example.test', 'role' => 'admin'])->assertForbidden();
        $this->postJson('/api/v1/workspaces/'.$this->workspace->id.'/invitations', ['email' => 'owner@example.test', 'role' => 'owner'])->assertUnprocessable();
    }

    private function invitationToken(): string
    {
        $token = '';
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        return $token;
    }
}
