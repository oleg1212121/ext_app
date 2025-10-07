<div>
    <h1>Hello</h1>
    <form action="/collect" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name='file'>
        <button class="border border-red-100 p-2 mt-2">Send</button>
    </form>
</div>
