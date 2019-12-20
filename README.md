# switchwαtch
SNMP based real-time network monitoring

The purpose of this script is to show real time interface statistics for SNMP enabled devices.
One major benefit is an overview of unused switch ports and when the port changed it's state, something I miss on CLI using cisco switches.

Due to the 32bit limitation, date counters overflow every 497 days, so uptime and port status change may be inaccurate.
Uptime can only be guessed if any port is having a higher tick count than the uptime, in that case uptime is accurate to ~900 days.

Script has a database to prevent simultaneous requests to the same device within 5 minutes.  That's currently the only use of database.

Cisco devices are speedy even over WAN.
Extreme Network devices are painful slow.
