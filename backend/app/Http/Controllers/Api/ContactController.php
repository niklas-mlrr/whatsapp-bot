<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    /**
     * Get all contacts for the current user.
     */
    public function index(Request $request)
    {
        try {
            $user = User::getFirstUser();
            
            $query = Contact::forUser($user->id)
                ->orderBy('name', 'asc');
            
            // Optional search
            if ($request->has('search')) {
                $query->search($request->input('search'));
            }
            
            $contacts = $query->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $contacts
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch contacts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch contacts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single contact.
     */
    public function show(Contact $contact)
    {
        try {
            $user = User::getFirstUser();
            
            // Ensure the contact belongs to the current user
            if ($contact->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contact not found'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $contact
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch contact: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new contact.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:100',
                'profile_picture_url' => 'nullable|string',
                'bio' => 'nullable|string|max:500',
                'metadata' => 'nullable|array',
            ]);
            
            $user = User::getFirstUser();
            
            // Normalize phone to WhatsApp JID format
            $validated['phone'] = Contact::normalizePhone($validated['phone']);
            $validated['user_id'] = $user->id;
            
            // Check if contact already exists
            $existingContact = Contact::where('user_id', $user->id)
                ->where('phone', $validated['phone'])
                ->first();
            
            if ($existingContact) {
                // Update existing contact
                $existingContact->update($validated);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Contact updated successfully',
                    'data' => $existingContact
                ]);
            }
            
            // Create new contact
            $contact = Contact::create($validated);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contact created successfully',
                'data' => $contact
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create contact', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create contact: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing contact.
     */
    public function update(Request $request, Contact $contact)
    {
        try {
            $user = User::getFirstUser();
            
            // Ensure the contact belongs to the current user
            if ($contact->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contact not found'
                ], 404);
            }
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:100',
                'profile_picture_url' => 'nullable|string',
                'bio' => 'nullable|string|max:500',
                'metadata' => 'nullable|array',
            ]);
            
            // Normalize phone if provided
            if (isset($validated['phone'])) {
                $validated['phone'] = Contact::normalizePhone($validated['phone']);
            }
            
            $contact->update($validated);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contact updated successfully',
                'data' => $contact
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update contact', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update contact: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a contact.
     */
    public function destroy(Contact $contact)
    {
        try {
            $user = User::getFirstUser();
            
            // Ensure the contact belongs to the current user
            if ($contact->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contact not found'
                ], 404);
            }
            
            $contact->delete();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contact deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete contact', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete contact: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find contact by phone number.
     */
    public function findByPhone(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone' => 'required|string'
            ]);
            
            $user = User::getFirstUser();
            $phone = Contact::normalizePhone($validated['phone']);
            
            $contact = Contact::where('user_id', $user->id)
                ->where('phone', $phone)
                ->first();
            
            if (!$contact) {
                return response()->json([
                    'status' => 'success',
                    'data' => null
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $contact
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to find contact: ' . $e->getMessage()
            ], 500);
        }
    }
}
