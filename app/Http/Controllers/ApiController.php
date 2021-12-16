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

    /**
     * Reply message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function replyMessage(Request $request)
    {
        DB::beginTransaction();
        try {
            /** @var Message $message */
            $message = Message::findOrFail($request->message_id);

            $reply = $message->children()->create([
                'parent_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message' => $request->message,
            ]);

            DB::commit();
            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'message_id' => $reply->id,
                    'message' => $reply->message,
                    'created_at' => $reply->getOriginal('created_at'),
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
     * Get conversations
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConversations(Request $request)
    {
        try {
            $conversations = DB::table('conversations')->select([
                DB::raw("IF(sender_id > receiver_id, CONCAT_WS(',', receiver_id, sender_id), CONCAT_WS(',', sender_id, receiver_id)) as user_ids"),
                DB::raw('GROUP_CONCAT(conversations.id) as conversation_ids'),
            ])
                ->where('sender_id', $request->user_id)
                ->orWhere('receiver_id', $request->user_id)
                ->groupBy('user_ids')
                ->get();

            $data = $conversations->map(function ($item) use ($request) {
                $users = DB::table('users')->whereIn('id', explode(',', $item->user_ids))->get();
                $conversations = DB::table('conversations')->whereIn('id', explode(',', $item->conversation_ids))->get();

                $title = $users->pluck('name')->join('-');
                $lastMessage = Message::select([
                    'messages.*',
                ])
                    ->whereIn('messages.conversation_id', $conversations->pluck('id')->toArray())
                    ->orderBy('messages.created_at', 'desc')
                    ->firstOrNew([]);
                $unreadMessage = $conversations->where('sender_id', $request->user_id)->first();

                return [
                    'title' => $title,
                    'last_message' => [
                        'user' => $lastMessage->conversation ? $lastMessage->conversation->sender->name : null,
                        'message' => $lastMessage->message,
                        'created_at' => $lastMessage->getOriginal('created_at'),
                    ],
                    'unread' => $unreadMessage->unread
                ];
            });

            return response()->json([
                'status' => 'SUCCESS',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'FAIL',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
