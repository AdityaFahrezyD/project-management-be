<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollaborationController;
use App\Http\Controllers\Api\V1\DependencyController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->scopeBindings()->group(function (): void {
    Route::prefix('auth')->name('auth.')->middleware('frontend.session')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:recovery')->name('register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:recovery')->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:recovery')->name('reset-password');
        Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'throttle:api'])->name('logout');
        Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::patch('profile', [AuthController::class, 'updateProfile'])->name('profile');
            Route::put('password', [AuthController::class, 'changePassword'])->name('password');
            Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verify'])->middleware('signed')->name('verify');
            Route::post('email/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:verification')->name('resend');
            Route::get('sessions', [AuthController::class, 'sessions'])->name('sessions');
            Route::delete('sessions/{session}', [AuthController::class, 'revokeSession'])->name('sessions.destroy');
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'verified', 'throttle:api'])->group(function (): void {
        Route::apiResource('workspaces', WorkspaceController::class);
        Route::prefix('workspaces/{workspace}')->name('workspaces.')->group(function (): void {
            Route::post('archive', [WorkspaceController::class, 'archive'])->name('archive');
            Route::post('restore', [WorkspaceController::class, 'restore'])->name('restore');
            Route::post('transfer-ownership', [WorkspaceController::class, 'transfer'])->name('transfer');
            Route::get('summary', [WorkspaceController::class, 'summary'])->name('summary');
            Route::get('members', [WorkspaceController::class, 'members'])->name('members.index');
            Route::patch('members/{membership}', [WorkspaceController::class, 'updateMember'])->name('members.update');
            Route::delete('members/{membership}', [WorkspaceController::class, 'removeMember'])->name('members.destroy');
            Route::get('projects', [WorkspaceController::class, 'projects'])->name('projects.index');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'revoke'])->name('invitations.revoke');
            Route::get('activity', [AuditController::class, 'workspace'])->name('activity');
        });
        Route::post('invitations/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

        Route::apiResource('projects', ProjectController::class)->except('store');
        Route::prefix('projects/{project}')->name('projects.')->group(function (): void {
            Route::patch('status', [ProjectController::class, 'status'])->name('status');
            Route::post('archive', [ProjectController::class, 'archive'])->name('archive');
            Route::post('restore', [ProjectController::class, 'restore'])->name('restore');
            Route::get('summary', [ProjectController::class, 'summary'])->name('summary');
            Route::get('members', [ProjectController::class, 'members'])->name('members.index');
            Route::post('members', [ProjectController::class, 'addMember'])->name('members.store');
            Route::patch('members/{membership}', [ProjectController::class, 'updateMember'])->name('members.update');
            Route::delete('members/{membership}', [ProjectController::class, 'removeMember'])->name('members.destroy');
            Route::get('dependencies', [DependencyController::class, 'index'])->name('dependencies.index');
            Route::post('dependencies', [DependencyController::class, 'store'])->name('dependencies.store');
            Route::delete('dependencies/{dependency}', [DependencyController::class, 'destroy'])->name('dependencies.destroy');
            Route::get('activity', [AuditController::class, 'project'])->name('activity');
            Route::apiResource('tasks', TaskController::class);
            Route::prefix('tasks/{task}')->name('tasks.')->group(function (): void {
                Route::patch('status', [TaskController::class, 'status'])->name('status');
                Route::put('assignees', [TaskController::class, 'assign'])->name('assignees');
                Route::post('archive', [TaskController::class, 'archive'])->name('archive');
                Route::post('restore', [TaskController::class, 'restore'])->name('restore');
                Route::get('history', [TaskController::class, 'history'])->name('history');
                Route::get('comments', [CollaborationController::class, 'comments'])->name('comments.index');
                Route::post('comments', [CollaborationController::class, 'storeComment'])->name('comments.store');
                Route::patch('comments/{comment}', [CollaborationController::class, 'updateComment'])->name('comments.update');
                Route::delete('comments/{comment}', [CollaborationController::class, 'destroyComment'])->name('comments.destroy');
                Route::get('attachments', [CollaborationController::class, 'attachments'])->name('attachments.index');
                Route::post('attachments', [CollaborationController::class, 'upload'])->name('attachments.store');
                Route::get('attachments/{attachment}/download', [CollaborationController::class, 'download'])->name('attachments.download');
                Route::delete('attachments/{attachment}', [CollaborationController::class, 'destroyAttachment'])->name('attachments.destroy');
            });
        });
    });
});
