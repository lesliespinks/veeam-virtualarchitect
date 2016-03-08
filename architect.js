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
veeamSettings.pProxyCores = 20;

// default amount of cores per proxy
// assuming physical proxy with 20 cores
veeamSettings.vProxyCores = 4;

// number of VMs per job
veeamSettings.VMsPerJobClassic = 20;
veeamSettings.VMsPerJobPerVMChain = 100;

architect.VMsPerCore = function(backupWindow, changeRate, averageVMSize) {
    return ( ( (veeamSettings.VMsPerCore * (veeamSettings.changeRate/changeRate) ) * (backupWindow/veeamSettings.backupWindow) ) * (veeamSettings.averageVMSize/averageVMSize) );
};

architect.storageThroughput = function(usedTB, changeRate, backupWindow) {
    var usedMB = usedTB * 1000 * 1000;
    var incBackup = usedMB * (changeRate / 100);
    var backupTime = backupWindow * 3600;

    return Math.round(incBackup/backupTime);
};


architect.coresRequired = function(numVMs, VMsPerCore) {

    var result = ( Math.ceil( (numVMs / VMsPerCore) / 4 ) * 4 );
    var calcThroughput = result * (veeamSettings.processingPerCore / veeamSettings.incrementalPenalty);

    // check if the raw throughput is higher than the calculated
    // cores - if yes, add a few more cores
    // [TBD] Need to write better comments, because I do not remember my own logic behind this wizardry.
    client.fullBackup = (numVMs * client.averageVMSize)*1024;
    client.incBackup = client.fullBackup * client.changeRate;

    var rawThroughput = client.incBackup / (client.backupWindow * 3600);

    if (rawThroughput > calcThroughput) {
        return ( Math.ceil( (rawThroughput / (veeamSettings.processingPerCore / veeamSettings.incrementalPenalty) ) / 4 ) * 4 );
    } else {
        return result;
    }

};

// [TBD] Fix the calculation method used for repositories
architect.repositoryServerCores = function(concurrentJobs) {
    return (Math.ceil((concurrentJobs)/2)*2);
}

architect.repositoryServerRAM = function(concurrentJobs) {
    return concurrentJobs * 4;
}

architect.applianceCores = function(proxyCores, repositoryCores) {
    // Doing nothing but rounding up to nearest 4 cores. So pretty.
    return (Math.ceil((proxyCores+repositoryCores)/4)*4);
}

/**
 * Backup & Replication Server
 *
 * @param numVMs {number} Number of virtual machines
 * @param mode {string} "pervm" or "classic"
 * @param offsite {boolean}
 * @return {object} Information about backup server and jobs
*/
architect.vbrServer = function(numVMs, mode, offsite) {

    var result = {}

    result.offsite = false;
    result.copyJobs = 0;

    if (mode == "classic") {
        result.jobs = Math.ceil(numVMs / veeamSettings.VMsPerJobClassic);
    }

    if (mode == "pervm") {
        result.jobs = Math.ceil(numVMs / veeamSettings.VMsPerJobPerVMChain);
    }

    if (offsite == true) {
        result.copyJobs = result.jobs;
        result.offsite = true;
    }

    result.totalJobs = result.jobs + result.copyJobs;

    result.CPU = ( Math.ceil( result.totalJobs / 10 / 2 ) * 2 );
    result.RAM = ( Math.ceil( (result.totalJobs / 10) * 4 ) );

    return result;
}

/**
 * SQL Server database
 *
 * @param numJobs {number} Number of concurrent jobs
 * @returns {object} Information about SQL Server
 */
architect.SQLDatabase = function(numJobs) {

    var databaseCorePerJob = 0.08;

    var result = {};
    result.CPU = Math.ceil(numJobs * databaseCorePerJob);

    if (result.CPU < 2) {
        result.CPU = 2;
    }

    result.RAM = result.CPU * 2;

    return result;
}