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

    /**
     * Get message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessages(Request $request)
    {
        DB::beginTransaction();
        try {
            $messages = Message::select([
                'messages.*',
                'a.sender_id',
                'a.receiver_id',
            ])
                ->join('conversations as a', 'a.id', '=', 'messages.conversation_id')
                ->where(function (Builder $builder) use ($request) {
                    $builder->where('a.sender_id', $request->user_id)->where('a.receiver_id', $request->other_user_id);
                })
                ->orWhere(function (Builder $builder) use ($request) {
                    $builder->where('a.receiver_id', $request->user_id)->where('a.sender_id', $request->other_user_id);
                })
                ->orderBy('messages.created_at', 'asc')
                ->get();

            // set unread to "0"
            $senderMessage = $messages->where('sender_id', $request->user_id)->first();
            Conversation::where('id', $senderMessage->conversation_id)->update([
                'unread' => 0
            ]);

            $data = $messages->map(function ($item) use ($request) {
                $parent = $item->parent;
                $item = [
                    'type' => $item->sender_id == $request->user_id ? 'send' : 'receive',
                    'message_id' => $item->id,
                    'message' => $item->message,
                    'created_at' => $item->getOriginal('created_at'),
                ];

                // get reply message
                if ($parent) {
                    $item['reply_from'] = [
                        'message_id' => $parent->id,
                        'message' => $parent->message,
                    ];
                }

                return $item;
            });

            DB::commit();
            return response()->json([
                'status' => 'SUCCESS',
                'data' => $data
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
