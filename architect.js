var client = {}
var veeamSettings = {}
var architect = {};

// average reduction in throughput at incremental passes
// typical bottleneck is random read at production storage
// note: may be much lower for e.g. all flash arrays
veeamSettings.incrementalPenalty = 5;

// average processing speed per single VMDK read
veeamSettings.processingPerCore = 50;

// average VM size in GB
veeamSettings.averageVMSize = 100;

// baseline backup window in hours (actual data transfer)
veeamSettings.backupWindow = 8;

// baseline change rate in decimal
veeamSettings.changeRate = 0.10;

// multiplier for number of VMs per backup window
// the best practices rule is 30 VMs per 8 hour window
// assuming 10% change, 8 hours backup window and 100 GB VM size
veeamSettings.VMsPerCore = 30;

// default amount of cores per proxy
// assuming physical proxy with 20 cores
veeamSettings.proxyCores = 20;

// number of VMs per job
veeamSettings.VMsPerJobClassic = 50;
veeamSettings.VMsPerJobPerVMChain = 100;

// GB RAM per CPU core
veeamSettings.vbrServerRAM = 4;

architect.VMsPerCore = function(backupWindow, changeRate, averageVMSize) {
    return ( ( (veeamSettings.VMsPerCore * (veeamSettings.changeRate/changeRate) ) * (backupWindow/veeamSettings.backupWindow) ) * (veeamSettings.averageVMSize/averageVMSize) );
};

architect.storageThroughput = function(usedTB, changeRate, backupWindow) {
    var usedMB = usedTB * 1024;
    var incBackup = usedMB * changeRate;
    var backupTime = backupWindow * 3600;

    return incBackup/backupTime;
};

architect.coresRequired = function(numVMs, VMsPerCore) {

    var result = ( Math.ceil( (numVMs / VMsPerCore) / 4 ) * 4 );
    var calcThroughput = result * (veeamSettings.processingPerCore / veeamSettings.incrementalPenalty);

    // check if the raw throughput is higher than the calculated
    // cores - if yes, add a few more cores
    client.fullBackup = (numVMs * client.averageVMSize)*1024;
    client.incBackup = client.fullBackup * client.changeRate;

    var rawThroughput = client.incBackup / (client.backupWindow * 3600);

    if (rawThroughput > calcThroughput) {
        return ( Math.ceil( (rawThroughput / (veeamSettings.processingPerCore / veeamSettings.incrementalPenalty) ) / 4 ) * 4 );
    } else {
        return result;
    }

};

architect.vbrServerCores = function(numVMs, mode) {

    var numCores = 2;
    var calcJobs;

    if (mode == "classic") {
        calcJobs = Math.ceil(numVMs / veeamSettings.VMsPerJobClassic);
    }

    if (mode == "pervm") {
        calcJobs = Math.ceil(numVMs / veeamSettings.VMsPerJobPerVMChain);
    }

    var calcCores = ( Math.ceil( calcJobs / 10 ) * 2 );

    return (calcCores > numCores) ? calcCores : numCores;

}


// Using these parameters, estimate how many VMs one task can process
// assuming no other bottlenecks
//client.VMsPerCore = architect.VMsPerCore(client.backupWindow, client.changeRate, client.averageVMSize);

//client.numVMs = 500;
//client.coresRequired = architect.coresRequired(client.numVMs, client.VMsPerCore);



/*console.log(architect.VMsPerCore(4, 0.1, 100));
console.log(architect.VMsPerCore(8, 0.05, 100));
console.log(architect.VMsPerCore(8, 0.05, 200));*/