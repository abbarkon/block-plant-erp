BLOCK PLANT ERP - CLIENT SETUP
==============================

This folder connects this computer to the Block Plant ERP system that runs on
the office server. Nothing is installed on this PC and no data is stored here.

SERVER
  http://192.168.5.96/block_plant/

HOW TO SET IT UP
  1. Copy this whole folder to the computer (for example to the Desktop or to
     C:\BlockPlantClient).
  2. Double-click  Install-Client.bat
  3. Wait for the "Done" message. A shortcut named "Block Plant ERP" appears on
     the Desktop and in the Start Menu.
  4. Double-click the shortcut and sign in with the account your administrator
     gave you.

WHAT THE SETUP DOES
  - Checks that the server answers.
  - Creates the desktop and Start Menu shortcuts.
  It does not install software, change Windows settings or copy any company data.

IF THE SHORTCUT DOES NOT OPEN
  1. Make sure the server PC is switched on and connected to the office network.
  2. Run  Test-Connection.bat  to see whether the server answers.
  3. If the server address changed, ask your administrator for the new address
     and replace the contents of  server-url.txt  with it (keep the trailing /).
  4. Check that you are on the office network (not a guest Wi-Fi or mobile data).

TO REMOVE THE SHORTCUTS
  Run  Uninstall-Client.bat

NOTES
  - The server must stay switched on while people use the system.
  - Only one copy of the database exists - on the server. Every PC sees the same
    data, so no synchronisation is needed.
  - Do not rename the server PC or change its IP address without rebuilding this
    package (run Create-Client-Package.bat on the server again).
