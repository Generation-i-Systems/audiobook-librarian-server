<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends Controller
{
    private const DEVELOPER_EMAIL = 'eric.thelin+librarian@gmail.com';

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'                           => 'required|in:bug,comment,suggestion',
            'message'                            => 'required|string|max:10000',
            'relatedData'                        => 'nullable|array',
            'relatedData.userId'                 => 'nullable|string',
            'relatedData.userEmail'              => 'nullable|string|email',
            'relatedData.deviceId'               => 'nullable|string',
            'relatedData.deviceName'             => 'nullable|string',
            'relatedData.appVersion'             => 'nullable|string',
            'relatedData.platformOs'             => 'nullable|string',
            'relatedData.bookSnapshot'           => 'nullable|array',
            'relatedData.sessionErrors'          => 'nullable|array',
            'relatedData.recentActivity'         => 'nullable|array',
            'fullData'                           => 'nullable|array',
            'fullData.logs'                      => 'nullable|array',
            'fullData.apiCalls'                  => 'nullable|array',
        ]);

        $user = Auth::user();
        $category = $validated['category'];
        $message = $validated['message'];

        $userName = $user !== null ? $user->name : 'Unknown';
        $subject = "[Audiobook Librarian Feedback] [{$category}] from {$userName}";

        $body = $this->buildEmailBody($validated, $user);

        try {
            Mail::raw($body, function ($mail) use ($subject) {
                $mail->to(self::DEVELOPER_EMAIL)
                     ->subject($subject);
            });
        } catch (\Exception $e) {
            Log::error('Failed to send feedback email', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to send feedback'], 500);
        }

        Log::info('Feedback received', [
            'category' => $category,
            'user_id'  => $user?->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Feedback received, thank you!']);
    }

    private function buildEmailBody(array $data, $user): string
    {
        $lines = [];

        $lines[] = "Category: " . strtoupper($data['category']);
        $userName = $user !== null ? $user->name : 'Anonymous';
        $userEmail = $user !== null ? $user->email : 'unknown';
        $lines[] = "Submitted by: {$userName} <{$userEmail}>";
        $lines[] = "";
        $lines[] = "Message:";
        $lines[] = $data['message'];

        if (isset($data['relatedData'])) {
            $rd = $data['relatedData'];
            $lines[] = "";
            $lines[] = "=== RELATED DATA ===";
            $lines[] = "User ID: " . ($rd['userId'] ?? 'n/a');
            $lines[] = "User Email: " . ($rd['userEmail'] ?? 'n/a');
            $lines[] = "Device ID: " . ($rd['deviceId'] ?? 'n/a');
            $lines[] = "Device Name: " . ($rd['deviceName'] ?? 'n/a');
            $lines[] = "App Version: " . ($rd['appVersion'] ?? 'n/a');
            $lines[] = "Platform: " . ($rd['platformOs'] ?? 'n/a');

            if (!empty($rd['bookSnapshot'])) {
                $book = $rd['bookSnapshot'];
                $lines[] = "";
                $lines[] = "Current Book:";
                $lines[] = "  Title: " . ($book['title'] ?? 'n/a');
                $lines[] = "  Author: " . ($book['author'] ?? 'n/a');
                $lines[] = "  Chapter: " . ($book['chapterTitle'] ?? 'n/a');
                $posMs = $book['positionMs'] ?? 0;
                $lines[] = "  Position: " . gmdate('H:i:s', intval($posMs / 1000));
            }

            if (!empty($rd['sessionErrors'])) {
                $lines[] = "";
                $lines[] = "Session Errors (" . count($rd['sessionErrors']) . "):";
                foreach ($rd['sessionErrors'] as $err) {
                    $lines[] = "  [{$err['timestamp']}][{$err['tag']}] {$err['message']}";
                    if (!empty($err['stackTrace'])) {
                        $lines[] = "    " . str_replace("\n", "\n    ", $err['stackTrace']);
                    }
                }
            }

            if (!empty($rd['recentActivity'])) {
                $lines[] = "";
                $lines[] = "Recent Activity (last " . count($rd['recentActivity']) . " events):";
                foreach (array_slice($rd['recentActivity'], 0, 20) as $activity) {
                    $lines[] = "  " . $activity;
                }
            }
        }

        if (isset($data['fullData'])) {
            $fd = $data['fullData'];

            if (!empty($fd['apiCalls'])) {
                $lines[] = "";
                $lines[] = "=== API CALLS (last " . count($fd['apiCalls']) . ") ===";
                foreach ($fd['apiCalls'] as $call) {
                    $status = $call['statusCode'] ?? '?';
                    $duration = isset($call['durationMs']) ? "{$call['durationMs']}ms" : '?ms';
                    $lines[] = "[{$call['timestamp']}] {$call['method']} {$call['url']} → {$status} ({$duration})";
                    if (!empty($call['requestBody'])) {
                        $lines[] = "  REQ: " . substr($call['requestBody'], 0, 500);
                    }
                    if (!empty($call['responseBody'])) {
                        $lines[] = "  RES: " . substr($call['responseBody'], 0, 500);
                    }
                }
            }

            if (!empty($fd['logs'])) {
                $lines[] = "";
                $lines[] = "=== LOGS (last " . count($fd['logs']) . " entries) ===";
                foreach ($fd['logs'] as $log) {
                    $lines[] = "[{$log['timestamp']}][{$log['level']}][{$log['tag']}] {$log['message']}";
                    if (!empty($log['throwable'])) {
                        $lines[] = "  " . str_replace("\n", "\n  ", substr($log['throwable'], 0, 1000));
                    }
                }
            }
        }

        return implode("\n", $lines);
    }
}
