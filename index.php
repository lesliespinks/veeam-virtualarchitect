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

        if (usedTB > 0) {
          client.averageVMSize = Math.round((usedTB * 1000) / numVMs);
        }

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

      var switchSplitFullBackup = $('#backupSplit').bootstrapSwitch();
      switchSplitFullBackup.on('switchChange.bootstrapSwitch', function(event, state) {
        if(state) {
          client.fullSplitDays = 7;
        } else {
          client.fullSplitDays = 1;
        }

        updateResults($('#numVMs').val());
      });

      // Draw the results

      function updateResults(numVMs) {

        var changeRate = $('#changeRate').val();
        if (changeRate == 0) {
          changeRate = 1
          $('#changeRate').val(changeRate);
        }

        var backupWindow = $('#backupWindow').val();
        if (backupWindow == 0) {
          backupWindow = 1;
          $('#backupWindow').val(backupWindow);
        }

        $('#usedTB').val( Math.round((numVMs * client.averageVMSize) / 1000) );
        var usedTB = $('#usedTB').val();

        // Calculate stuff for B&R server and the SQL backend
        var vbrServer = architect.vbrServer(numVMs, 'pervm', client.backupCopyEnabled);
        var vbrSQL = architect.SQLDatabase(vbrServer.totalJobs);
        var vbrProxy = architect.proxyServer(numVMs);
        var vbrRepository = architect.repositoryServer(vbrProxy.CPU);

        if (client.fullSplitDays > 1) {
          $('#storageThroughput').html(
            '<h3>Periodical full backup</h3>' +
            '<p>When enabling <b>Split full backup</b>, the tool assumes periodical full backups are spread throughout the week ' +
            'and increases proxy server compute requirements accordingly.' +
            '</p>' +
            '<p>Daily full size is <b>' + Math.round(vbrProxy.partialFullBackup / 1024 / 1024) + ' TB</b> daily at <b>' + vbrProxy.partialFullThroughput + ' MB/s</b>' +
            '<p>Daily incremental size is <b>' + Math.round(vbrProxy.partialIncBackup / 1024 / 1024) +' TB</b> at <b>' + vbrProxy.partialIncThroughput + ' MB/s</b>' +
            '<p>' +
            'Total throughput is increased to <b>' + Math.round(vbrProxy.partialFullThroughput+vbrProxy.partialIncThroughput) + ' MB/s</b>' +
            '</p>'
          )
        } else {
          $('#storageThroughput').html(
            '<h3>Incremental forever</h3>' +
            '<p>' +
            'Daily changed data is <b>' + Math.round( (vbrProxy.incBackup) / 1024 / 1024) +' TB</b>, and thus assuming production storage can sustain ' +
            '<b>' + Math.round(vbrProxy.incThroughput) + ' MB/s</b>' + ' throughout the backup window.</p>'
          )
        }

        // Begin calculating stuff for proxy servers
        client.changeRate = changeRate / 100;
        client.backupWindow = backupWindow;
        client.fullBackupWindow = backupWindow*2;

        var applianceCores = architect.applianceCores(vbrProxy.CPU, vbrRepository.CPU);
        var applianceRAM = (vbrProxy.RAM + vbrRepository.RAM);
        var physAppliance = Math.ceil( applianceCores / client.pProxyCores );

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
            var jobString = '<p>' + vbrServer.jobs + ' backup ' + pluralize('job', vbrServer.jobs) + '<br />' +
              vbrServer.copyJobs + ' backup copy or tape ' + pluralize('job', vbrServer.copyJobs) + '</p>';
          } else {
            var jobString = '<p>' + vbrServer.jobs + ' backup ' + pluralize('job', vbrServer.jobs) + '<br />&nbsp;</p>';
          }

          $('#vbrServerResult').html('<div class="well"><h1>Backup & Replication</h1>' +
            jobString +
            '<b>System requirements</b>' +
            '<div class="row">' +
            ' <div class="col-md-6 col-xs-4">Backup & Replication</div>' +
            ' <div class="col-md-6 col-xs-4">' + vbrServer.CPU + ' cores and ' + vbrServer.RAM + ' GB RAM</div>' +
            '</div>' +
            '<div class="row">' +
            ' <div class="col-md-6 col-xs-4">SQL Server</div>' +
            ' <div class="col-md-6 col-xs-4">' + vbrSQL.CPU + ' cores and ' + vbrSQL.RAM + ' GB RAM</div>' +
            '</div>' +
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
              '<p>' + vbrProxy.pNumProxy + ' physical proxy ' + pluralize('server', vbrProxy.pNumProxy) + '<br />' +
              vbrProxy.vNumProxy + ' virtual proxy ' + pluralize('server', vbrProxy.vNumProxy) + '</p>' +
              'System requirements: ' + vbrProxy.CPU + ' cores and ' + vbrProxy.RAM + ' GB RAM' +
              '' +
              '</div>');

            $('#repositoryResult').html('<div class="well">' +
              '<h1>Backup Repository</h1>' +
              '<p>The following sizing assumes all jobs run simultaneously.</p>' +
              'System requirements: ' + vbrRepository.CPU + ' cores and ' + vbrRepository.RAM + ' GB RAM' +
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
        <button class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navcol-1"><span class="sr-only">
            Toggle navigation</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
        </button>
      </div>
      <div class="collapse navbar-collapse" id="navcol-1">
        <ul class="nav navbar-nav">
          <li role="presentation"><a href="http://rps.dewin.me" target="new">Restore Point Simulator</a></li>
          <li class="active" role="presentation"><a href="#">Virtual Architect</a></li>
          <li role="presentation"><a href="http://bp.veeam.expert" target="new">Best Practices Wiki</a></li>
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

          <div class="row">
            <div class="col-md-6">
              <div class="form-group input-group-lg">
                <label for="backupWindow">Backup window</label>
                <input type="number" min="0" max="24" step="1" name="backupWindow" id="backupWindow" class="form-control input-lg" autocomplete="off" />
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group input-group-lg">
                <label for="backupSplit">Split full backup</label>
                <p><input type="checkbox" name="backupSplit" id="backupSplit" /></p>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="form-group">
              <label for="combineProxyRepo">Appliance&nbsp;mode</label>
              <p><input type="checkbox" name="combineProxyRepo" id="combineProxyRepo" /></p>
            </div>
          </div>

          <div class="col-md-6">
            <div class="form-group">
              <label for="backupCopyEnabled">Offsite&nbsp;backups</label>
              <p><input type="checkbox" name="backupCopyEnabled" id="backupCopyEnabled" /></p>
            </div>
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
