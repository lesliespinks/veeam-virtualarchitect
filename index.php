<?php
/**
* FILE
*/
error_reporting(E_ALL || E_STRICT);

require 'vendor/autoload.php';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Virtual Architect</title>
  <link rel="stylesheet" type="text/css" href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" type="text/css" href="vendor/twbs/bootstrap/dist/css/bootstrap-theme.min.css" />
  <link rel="stylesheet" type="text/css" href="vendor/nostalgiaz/bootstrap-switch/dist/css/bootstrap3/bootstrap-switch.css" />
  <script type="text/javascript" src="vendor/components/jquery/jquery.min.js"></script>
  <script type="text/javascript" src="vendor/twbs/bootstrap/dist/js/bootstrap.min.js"></script>
  <script type="text/javascript" src="vendor/nostalgiaz/bootstrap-switch/dist/js/bootstrap-switch.min.js"></script>
  <script type="text/javascript" src="pluralize.js"></script>
  <script type="text/javascript" src="architect.js"></script>
  <script type="text/javascript">

    $(document).ready(function(){

      // Tweakable settings, but initialized with Veeam standards
      client = veeamSettings;

      $('#backupWindow').val(client.backupWindow);
      $('#changeRate').val(client.changeRate*100);

      // Disable the default submit
      $( "#customer-data" ).submit(function( event ) {
        event.preventDefault();
      });


      $('#usedTB').change(function() {
        var numVMs = $('#numVMs').val();
        var usedTB = $('#usedTB').val();

        if(!numVMs) {
          var calcVMs = (usedTB * 1000) / veeamSettings.averageVMSize
          $('#numVMs').val( calcVMs );
          numVMs = calcVMs;
        }

        client.averageVMSize = Math.round((usedTB * 1000) / numVMs);

        updateResults(numVMs);
      });

      $('#numVMs, #changeRate, #backupWindow').change(function() {
        var numVMs = $('#numVMs').val();
        updateResults(numVMs);
      });

      // Initialize the fancy switch boxes

      var switchCombineProxyRepo = $('#combineProxyRepo').bootstrapSwitch();
      switchCombineProxyRepo.on('switchChange.bootstrapSwitch', function(event, state) {
        client.combineProxyRepo = state;
        updateResults($('#numVMs').val());
      });

      var switchBackupCopyEnabled = $('#backupCopyEnabled').bootstrapSwitch();
      switchBackupCopyEnabled.on('switchChange.bootstrapSwitch', function(event, state) {
        client.backupCopyEnabled = state;
        updateResults($('#numVMs').val());
      });

      // Draw the results

      function updateResults(numVMs) {

        var changeRate = $('#changeRate').val();
        var backupWindow = $('#backupWindow').val();

        $('#usedTB').val( Math.round((numVMs * client.averageVMSize) / 1000) );
        var usedTB = $('#usedTB').val();

        // Begin calculating stuff for VBR Server
        var vbrCores = architect.vbrServerCores(numVMs, 'pervm');
        var vbrRAM = vbrCores * veeamSettings.vbrServerRAM;
        var vbrJobs = Math.ceil( numVMs / veeamSettings.VMsPerJobPerVMChain );

        if (client.backupCopyEnabled) {
          vbrCores = vbrCores*2;
          vbrRAM = vbrRAM*2;
        }

        // Calculate storage throughput

        var changeTB = Math.round(usedTB * (changeRate/100));

        $('#storageThroughput').html(
          '<p>' +
          'Daily changed data is <b>' + changeTB +' TB</b>, and thus assuming production storage can sustain ' +
          '<b>' + architect.storageThroughput(usedTB, changeRate, backupWindow) + ' MB/s</b>' +
          ' throughout the backup window.' +
          '</p>'
        );

        // Begin calculating stuff for proxy servers
        client.changeRate = changeRate / 100;
        client.backupWindow = backupWindow;
        client.VMsPerCore = architect.VMsPerCore(client.backupWindow, client.changeRate, client.averageVMSize);

        var coresRequired = architect.coresRequired(numVMs, client.VMsPerCore);
        var RAMRequired = coresRequired * 2;
        var physProxy = Math.ceil( coresRequired / veeamSettings.pProxyCores );
        var virtProxy = Math.ceil( coresRequired/ veeamSettings.vProxyCores );

        var repositoryCores = architect.repositoryServerCores(vbrJobs);
        var repositoryRAM = architect.repositoryServerRAM(vbrJobs);

        var applianceCores = architect.applianceCores(coresRequired, repositoryCores);
        var applianceRAM = (RAMRequired + repositoryRAM);
        var physAppliance = Math.ceil( applianceCores / veeamSettings.pProxyCores );

        // Output simple mode for small business deployment

        if (numVMs <= 150) {
          $('#vbrServerResult').html('<div class="well"><h1>Backup & Replication</h1>' +
            '<p>Don\'t worry. A single server is all you need.</p>' +
            'Go with 4 CPU and 8 GB RAM. Install all components in one box. ' +
            'Veeam has your back, once your environment grows. Try increasing the numbers to get an idea ' +
            'about when scaling out is a good idea.' +
            '</div>');

          $('#proxyResult').html('');
          $('#repositoryResult').html('');

        } else {

          // Backup server

          if (client.backupCopyEnabled) {
            var jobString = '<p>' + vbrJobs + ' backup ' + pluralize('job', vbrJobs) + '<br />' +
              vbrJobs + ' backup copy or tape ' + pluralize('job', vbrJobs) + '</p>';
          } else {
            var jobString = '<p>' + vbrJobs + ' backup ' + pluralize('job', vbrJobs) + '<br />&nbsp;</p>';
          }

          $('#vbrServerResult').html('<div class="well"><h1>Backup & Replication</h1>' +
            jobString +
            'System requirements: ' + vbrCores + ' cores and ' + vbrRAM + ' GB RAM' +
            '<br /><small style="color: red">[TBD] Does not limit job size based on average VM size<br />' +
            '[TBD] What about SQL?</small><br />' +
            '</div>'
          );

          if (client.combineProxyRepo == true) {
            $('#repositoryResult').html('');

            $('#proxyResult').html('<div class="well">' +
              '<h1>Proxy and repository servers</h1>' +
              '<p>Building your own backup appliance? This is the way to go.</p>' +
              '<p>' + physAppliance + ' physical ' + pluralize('appliance', physAppliance) + ' ' + pluralize('is', physAppliance) + ' required</p>' +
              'System requirements: ' + applianceCores + ' cores and ' + applianceRAM + ' GB RAM' +
              '' +
              '</div>');

          } else {
            $('#proxyResult').html('<div class="well"><h1>Proxy servers</h1>' +
              '<p>' + physProxy + ' physical proxy ' + pluralize('server', physProxy) + '<br />' +
              virtProxy + ' virtual proxy ' + pluralize('server', virtProxy) + '</p>' +
              'System requirements: ' + coresRequired + ' cores and ' + RAMRequired + ' GB RAM' +
              '' +
              '</div>');

            $('#repositoryResult').html('<div class="well">' +
              '<h1>Backup Repository</h1>' +
              '<p>The following sizing assumes all jobs run simultaneously.</p>' +
              'System requirements: ' + repositoryCores + ' cores and ' + repositoryRAM + ' GB RAM' +
              '</div>');
          }

        }

      };


      // FIXME
      // This should be good for GET parameters at a later stage

      /* if ($_GET equivalent something something)
      $('#numVMs').val(1000);
      $('#changeRate').val(12);
      $('#backupWindow').val(6);
      $('#numVMs').change();

      $('#usedTB').val(200);
      $('#usedTB').change();
      */
    });

  </script>
</head>

<body>
  <nav class="navbar navbar-default">
    <div class="container-fluid">
      <div class="navbar-header"><a class="navbar-brand navbar-link" href="/">Veeam<b>Toolbox</b></a>
        <button class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navcol-1"><span class="sr-only">Toggle navigation</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span></button>
      </div>
      <div class="collapse navbar-collapse" id="navcol-1">
        <ul class="nav navbar-nav">
          <li role="presentation"><a href="http://rps.dewin.me" target="new">Restore Point Simulator</a></li>
          <li class="active" role="presentation"><a href="#">Virtual Architect</a></li>
          <li role="presentation"><a href="#">Best Practices Wiki</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container-fluid">

      <div class="col-md-3">
        <form id="customer-data">
          <div class="form-group input-group-lg">
            <label for="numVMs">Number of VMs</label>
            <input type="number" min="0" step="50" name="numVMs" id="numVMs" class="form-control input-lg" autocomplete="off" autofocus />
          </div>

          <div class="form-group">
            <label for="usedTB">TB used</label>
            <div class="input-group">
              <input type="number" min="0" step="10" name="usedTB" id="usedTB" class="form-control input-lg" autocomplete="off"  />
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

          <div class="col-sm-push-4 clearfix">
            <label for="combineProxyRepo">Combine proxy and repository</label>
            <p><input type="checkbox" name="combineProxyRepo" id="combineProxyRepo" /></p>
          </div>

          <div class="col-sm-push-4 clearfix">
            <label for="backupCopyEnabled">Enable Backup Copy Job</label>
            <p><input type="checkbox" name="backupCopyEnabled" id="backupCopyEnabled" /></p>
          </div>
        </form>
        <div class="col-sm-push-2" id="storageThroughput">
          <!-- Placeholder -->
        </div>
      </div>
      <div class="col-md-8">
        <div class="row">
          <div class="col-md-6" id="vbrServerResult">
            <!-- Placeholder -->
          </div>
          <div class="col-md-6" id="proxyResult">
            <!-- Placeholder -->
          </div>
        </div>
        <div class="row">
          <div class="col-md-12" id="repositoryResult">
            <!-- Placeholder -->
          </div>
        </div>
      </div>
  </div>
  </body>
</html>
