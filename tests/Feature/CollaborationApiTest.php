<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use App\Services\CollaborationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class CollaborationApiTest extends ApiTestCase
{
    public function test_upload_download_is_private_and_deletion_removes_file_after_commit(): void
    {
        Storage::fake('local');
        $task = $this->task();
        $response = $this->postJson($this->taskUrl($task, '/attachments'), ['file' => UploadedFile::fake()->image('proof.png')])
            ->assertCreated()->assertJsonMissingPath('data.file_path');
        $attachment = Attachment::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($attachment->file_path);
        $url = $this->taskUrl($task, '/attachments/'.$attachment->id);
        $this->getJson($url.'/download')->assertOk()->assertDownload('proof.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $viewer = $this->member('viewer');
        $this->actingAs($viewer, 'web')->getJson($url.'/download')->assertOk();
        $this->deleteJson($url)->assertForbidden();
        $this->actingAs($this->manager, 'web')->deleteJson($url)->assertNoContent();
        Storage::disk('local')->assertMissing($attachment->file_path);
        $this->getJson($url.'/download')->assertNotFound();
    }

    public function test_fake_extension_oversize_and_malformed_office_are_rejected(): void
    {
        Storage::fake('local');
        $task = $this->task();
        $url = $this->taskUrl($task, '/attachments');
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->image('large.png')->size(10241)])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('fake.docx', 'Not Office')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdf_and_valid_office_containers_are_accepted(): void
    {
        Storage::fake('local');
        $task = $this->task();
        $url = $this->taskUrl($task, '/attachments');
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")])->assertCreated();
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'] as $extension => $entry) {
            $path = tempnam(sys_get_temp_dir(), 'office-test-');
            try {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
                $zip->addFromString($entry, '<?xml version="1.0"?><document/>');
                $zip->close();
                $this->postJson($url, ['file' => new UploadedFile($path, 'document.'.$extension, test: true)])->assertCreated();
            } finally {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_attachment_failure_rolls_back_row_audit_and_stored_file(): void
    {
        Storage::fake('local');
        $task = $this->task();
        $before = ActivityLog::count();
        Attachment::creating(function (): void {
            throw new RuntimeException('Simulated database failure');
        });
        try {
            app(CollaborationService::class)->upload($this->manager, $task, UploadedFile::fake()->image('proof.png'));
            $this->fail('Expected storage transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        } finally {
            Attachment::flushEventListeners();
        }
        $this->assertSame(0, Attachment::count());
        $this->assertSame($before, ActivityLog::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_author_and_manager_moderation_and_nested_comment_isolation(): void
    {
        $task = $this->task();
        $member = $this->member();
        $task->assignees()->attach($member->id);
        $this->actingAs($member, 'web');
        $id = $this->postJson($this->taskUrl($task, '/comments'), ['content' => 'Initial'])->assertCreated()->json('data.id');
        $url = $this->taskUrl($task, '/comments/'.$id);
        $this->patchJson($url, ['content' => 'Edited'])->assertOk();
        $other = $this->member();
        $task->assignees()->attach($other->id);
        $this->actingAs($other, 'web')->patchJson($url, ['content' => 'Denied'])->assertForbidden();
        $foreign = Comment::forceCreate(['task_id' => Task::factory()->create()->id, 'user_id' => $member->id, 'content' => 'Private']);
        $this->actingAs($this->manager, 'web')->getJson($this->taskUrl($task, '/comments'))->assertJsonCount(1, 'data');
        $this->deleteJson($this->taskUrl($task, '/comments/'.$foreign->id))->assertNotFound();
        $this->deleteJson($url)->assertNoContent();
        $this->getJson($this->projectUrl('/activity'))->assertOk();
    }
}
