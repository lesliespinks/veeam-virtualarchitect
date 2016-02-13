<?php
/**
* FILE
*/
error_reporting(E_ALL || E_STRICT);

require 'vendor/autoload.php';
?>
<html>
  <head>
    <title>Virtual Architect</title>
    <link rel="stylesheet" type="text/css" href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="vendor/twbs/bootstrap/dist/css/bootstrap-theme.min.css" />
    <script type="text/javascript" src="vendor/components/jquery/jquery.min.js"></script>
    <script type="text/javascript" src="vendor/twbs/bootstrap/dist/js/bootstrap.min.js"></script>
    <script type="text/javascript" src="pluralize.js"></script>
    <script type="text/javascript" src="architect.js"></script>
    <script type="text/javascript">

      $(document).ready(function(){

        // Tweakable settings, but initialized with Veeam standards
        client.backupWindow = veeamSettings.backupWindow;
        $('#backupWindow').val(client.backupWindow);
        client.changeRate = veeamSettings.changeRate;
        $('#changeRate').val(client.changeRate*100);
        client.averageVMSize = veeamSettings.averageVMSize;
        client.proxyCores = veeamSettings.proxyCores;

        $( "#customer-data" ).submit(function( event ) {
          event.preventDefault();
        });

        $('#customer-data').change(function() {

          // Begin calculating stuff for VBR Server
          var vbrCores = architect.vbrServerCores($('#numVMs').val(), 'pervm');
          var vbrRAM = vbrCores * veeamSettings.vbrServerRAM;
          var vbrJobs = Math.ceil( $('#numVMs').val() / veeamSettings.VMsPerJobPerVMChain );

          // Calculate UsedTB based on average VM size
          // Update it every time the form changes

          $('#usedTB').val( Math.round(($('#numVMs').val() * client.averageVMSize) / 1000) );

          // Calculate storage throughput


          // Begin calculating stuff for proxy servers
          client.changeRate = $('#changeRate').val() / 100;
          client.backupWindow = $('#backupWindow').val();

          client.VMsPerCore = architect.VMsPerCore(client.backupWindow, client.changeRate, client.averageVMSize);
          var coresRequired = architect.coresRequired($('#numVMs').val(), client.VMsPerCore);
          var RAMRequired = coresRequired * 2;
          var physProxy = Math.ceil( coresRequired / veeamSettings.proxyCores );
          var virtProxy = Math.ceil( coresRequired/4 ); // FIXME

          if ($('#numVMs').val() <= 150) {
            $('#vbrServerResult').html('<div class="jumbotron"><h1>Backup & Replication</h1>' +
              '<p>Don\'t worry. A single server is all you need.</p>' +
              'Go with 4 CPU and 8 GB RAM. Install all components in one box. ' +
              'Veeam has your back, once your environment grows. Try increasing the numbers to get an idea ' +
              'about when scaling out is a good idea.' +
              '</div>');

            $('#proxyResult').html('');

          } else {

            // Update the output
            $('#vbrServerResult').html('<div class="jumbotron"><h1>Backup & Replication</h1>' +
              '<p>' + vbrJobs + ' backup ' + pluralize('job', vbrJobs) + '</p>' +
              'System requirements: ' + vbrCores + ' cores and ' + vbrRAM + ' GB RAM' +
              '</div>'
            );

            $('#proxyResult').html('<div class="jumbotron"><h1>Proxy servers</h1>' +
              '<p>' + physProxy + ' physical proxy ' + pluralize('server', physProxy) + '<br />' +
              virtProxy + ' virtual proxy ' + pluralize('server', virtProxy) + '</p>' +
              'System requirements: ' + coresRequired + ' cores and ' + RAMRequired + ' GB RAM' +
              '' +
              '</div>');
          }

        });

      });

    </script>

  </head>
  <body>


  <div class="container-fluid">

    <div class="page-header">
      <h1>Virtual Architect</h1>
    </div>
    <div class="row">
      <div class="col-sm-2">
        <form id="customer-data">

          <div class="form-group input-group-lg">
            <label for="numVMs">Number of VMs</label>
            <input type="number" min="0" step="50" name="numVMs" id="numVMs" class="form-control input-lg" autocomplete="off" autofocus />
          </div>

          <div class="form-group">
            <label for="usedTB">TB used</label>
            <div class="input-group">
              <input type="number" min="0" name="usedTB" id="usedTB" class="form-control input-lg" autocomplete="off" disabled />
              <span class="input-group-addon">TiB</span>
            </div>
          </div>

          <div class="form-group">
            <label for="changeRate">Change rate</label>
            <div class="input-group">
              <input type="number" min="0" max="50" name="changeRate" id="changeRate" class="form-control input-lg" autocomplete="off" />
              <span class="input-group-addon">%</span>
            </div>
          </div>

          <div class="form-group input-group-lg">
            <label for="backupWindow">Backup window</label>
            <input type="number" min="0" max="24" step="1" name="backupWindow" id="backupWindow" class="form-control input-lg" autocomplete="off" />
          </div>

        </form>
        <div class="col-sm-push-2" id="storageThroughput">

        </div>
      </div>

      <div class="col-sm-4" id="vbrServerResult">

      </div>
      <div class="col-sm-4" id="proxyResult">

      </div>
    </div>

  </div>

  </body>
</html>
