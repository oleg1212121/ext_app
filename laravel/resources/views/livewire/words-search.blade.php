<div>
    <div class="mb-6">
        <label for="success" class="block mb-2 text-sm font-medium text-green-700">Your name</label>
        <input type="text" wire:model.live.debounce.300ms="search" id="success" class="bg-green-50 border border-green-500 text-green-900 placeholder-green-700 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" placeholder="Success input">
        <p class="mt-2 text-sm text-green-600"><span class="font-medium">Well done!</span> Some success message.</p>
    </div>
    {{-- <div>
        <label for="error" class="block mb-2 text-sm font-medium text-red-700">Your name</label>
        <input type="text" id="error" class="bg-red-50 border border-red-500 text-red-900 placeholder-red-700 text-sm rounded-lg focus:ring-red-500 focus:border-red-500 block w-full p-2.5" placeholder="Error input">
        <p class="mt-2 text-sm text-red-600"><span class="font-medium">Oh, snapp!</span> Some error message.</p>
    </div> --}}
  
    <ul>
        @foreach($words as $word)
            <li wire:key={{ $word->id }}> 
                {{ $word->word }}
            </li>
        @endforeach
    </ul>
    
</div>
