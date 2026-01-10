# dumb-maps
Map service for old devices with no javascript support and slow connections

How to change your position and zoom?
Use the links to move left, right, up, down and manipulate the zoom level. Everything is contained in an link that is also editable.

How do I download my map?
Use the download link to preview the map in an standalone page. This is helpfull because it skips compression if you use opera mini for example and you can easyly download it

How much on avrage does an map take up?
On avrage about 0.5MB but it depends on the complexity of an tile and your image compression level but remember each action generates an new tile so data usage can pile up quickly.

How long does it take to display an tile?
Depends, for new tiles 3-5 seconds depending on your connection. For the tiles that are cashed on the server around one second.

How long are the tiles kept?
For now it is 300 seconds and it someone makes an new file request each file older than 300 seconds will be deleted. Lower that number if you have a lot of traffic or the server is low on storage.

Can I use this as a base for my project/host it?
Yes, but please credit this repository or my github username. If you have further questions you can contact me directly at wojtasek3221@gmail.com

official map service avalable at dumb-maps.ct.ws
