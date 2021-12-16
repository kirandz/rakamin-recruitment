<?php

namespace App\Http\Controllers;

use App\Conversation;
use App\Message;
use App\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    /**
     * Send Message to another user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMessage(Request $request)
    {
        DB::beginTransaction();
        try {
            /** @var Conversation $senderConversation */
            $senderConversation = Conversation::firstOrNew([
                'sender_id' => $request->sender_id,
                'receiver_id' => $request->receiver_id,
            ]);
            $senderConversation->save();

            $message = $senderConversation->messages()->create([
                'conversation_id' => $senderConversation->id,
                'message' => $request->message,
            ]);

            // add unread message to receiver
            /** @var Conversation $receiverConversation */
            $receiverConversation = Conversation::firstOrNew([
                'receiver_id' => $request->sender_id,
                'sender_id' => $request->receiver_id,
            ]);
            $receiverConversation->unread = $receiverConversation->unread + 1;
            $receiverConversation->save();

            DB::commit();
            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'message_id' => $message->id,
                    'message' => $message->message,
                    'created_at' => $message->getOriginal('created_at'),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'FAIL',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
