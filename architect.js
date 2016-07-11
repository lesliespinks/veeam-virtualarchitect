var client = {};
var veeamSettings = {};
var architect = {};

// average reduction in throughput at incremental passes
// typical bottleneck is random read at production storage
// note: may be much lower for e.g. all flash arrays
veeamSettings.incrementalPenalty = 5;

// average processing speed per single VMDK read
// equivalent to 1,000 MB/s for a 16 core physical proxy 
// or 375 MB/s for a virtual proxy 
veeamSettings.processingPerCore = 62.5;

// Average VM size in GB
// 
// For an average VM size of default 100 GB, the 62.5 MB/s 
// per processing core is the equivalent to 18 VMs per 
// 8 hours per core for a full backup, or 36 VMs per 8 hours per core 
// for a 10% incremental pass with the $incrementalPenalty of 5x. 
//
// According to Best Practices guide, one should estimate 
// 30 VMs per CPU core, so these measures are 20% less 
// conservative.
//
// Consider increasing average VM size to make the number fit?
veeamSettings.averageVMSize = 100;

// baseline backup window in hours (actual data transfer)
veeamSettings.backupWindow = 8;
veeamSettings.fullBackupWindow = 16;
veeamSettings.fullSplitDays = 1;

// baseline change rate in decimal
//
// Reusing conservative change rate from Restore Point Simulator
veeamSettings.changeRate = 0.10;

// NOT USED! Multiplier for number of VMs per backup window
// the best practices rule is 30 VMs per 8 hour window
// assuming 10% change, 8 hours backup window and 100 GB VM size
//
// Can be calculated from $processingPerCore, $incrementalPenalty,
// $changeRate and $backupWindow.
veeamSettings.VMsPerCore = 30;

// Default CPU cores per physical proxy
veeamSettings.pProxyCores = 16;

// Default CPU cores per virtual proxy
veeamSettings.vProxyCores = 6;

// number of VMs per job
// Aligned with Best Practices for version 9. Keep assuming 
// per job chains, as this is the most conservative estimate.
//
// TODO: Add switch to "per VM" jobs
veeamSettings.VMsPerJobClassic = 30;
veeamSettings.VMsPerJobPerVMChain = 90;
veeamSettings.mode = "classic";

architect.round = function(num, round) {
		return (Math.ceil(num / round) * round);
};

architect.applianceCores = function(proxyCores, repositoryCores) {
		// Reducing the CPU required for repository by 50% when combined as repository.
		return architect.round(proxyCores+(repositoryCores*0.5),4);
};

/**
 * Backup Repository servers
 *
 * @param proxyCPU {number} Number of CPUs required for proxy servers
 * @returns {object} Information about repository servers
 */
architect.repositoryServer = function(proxyCPU) {
		var result = {};

		result.CPU = Math.ceil((proxyCPU * 0.5 ) / 2) * 2;
		result.RAM = result.CPU * 4;

		if (client.backupCopyEnabled) {
				result.CPU = Math.ceil(( (result.CPU * 2) * 0.65 ) / 2) * 2;
				result.RAM = Math.ceil((result.RAM * 2) * 0.65);
		}

		return result;
};

/**
 * Backup Proxy servers
 *
 * @param numVMs {number} Number of virtual machines
 * @returns {object} Information about proxy servers
 */
architect.proxyServer = function(numVMs) {
		var result = {};

		var pFullThroughput = veeamSettings.processingPerCore * veeamSettings.pProxyCores;
		var pIncThroughput = pFullThroughput / veeamSettings.incrementalPenalty;
		var vFullThroughput = veeamSettings.processingPerCore * veeamSettings.vProxyCores;
		var vIncThroughput = vFullThroughput / veeamSettings.incrementalPenalty;


		result.fullBackup = (numVMs * client.averageVMSize)*1024; // in MB
		result.incBackup = ((numVMs * client.averageVMSize)*1024) * client.changeRate; // in MB
		result.fullThroughput = Math.round( result.fullBackup/(3600*client.fullBackupWindow) ); // MB/s
		result.incThroughput = Math.round( result.incBackup/(3600*client.backupWindow) ); // MB/s

		pNumProxy = [];
		vNumProxy = [];

		// Check throughput required for each mode, and validate against
		// the throughput available for physical and virtual deployments
		pNumProxy.push(result.fullThroughput / pFullThroughput);
		pNumProxy.push(result.incThroughput / pIncThroughput);

		vNumProxy.push(result.fullThroughput / vFullThroughput);
		vNumProxy.push(result.incThroughput / vIncThroughput);

		if ( client.fullSplitDays > 1 ) {
				result.partialFullBackup = result.fullBackup/client.fullSplitDays;
				result.partialIncBackup = result.incBackup*((client.fullSplitDays-1)/client.fullSplitDays);
				result.partialFullThroughput = Math.round( (result.fullBackup/client.fullSplitDays)/(3600*client.backupWindow) );
				result.partialIncThroughput =  Math.round( (result.incThroughput * (client.fullSplitDays-1)/client.fullSplitDays) );

				pNumProxy.push(result.partialFullThroughput / pIncThroughput);
				vNumProxy.push(result.partialFullThroughput / vFullThroughput);
		}


		// Return only the max values for different calculation methods
		result.pNumProxy = Math.ceil( Math.max.apply(null, pNumProxy) );
		result.vNumProxy = Math.ceil( Math.max.apply(null, vNumProxy) );


		CPU = [];
		RAM = [];

		CPU.push(result.pNumProxy * veeamSettings.pProxyCores);
		RAM.push(result.pNumProxy * veeamSettings.pProxyCores * 2);
		CPU.push(result.vNumProxy * veeamSettings.vProxyCores);
		RAM.push(result.vNumProxy * veeamSettings.vProxyCores * 2);

		// Return only the min values for CPU/RAM requirement
		result.CPU = Math.ceil( Math.min.apply(null, CPU) );
		result.RAM = Math.ceil( Math.min.apply(null, RAM) );

		return result;
};

/**
 * Backup & Replication Server
 *
 * @param numVMs {number} Number of virtual machines
 * @param mode {string} "pervm" or "classic"
 * @param offsite {boolean}
 * @return {object} Information about backup server and jobs
*/
architect.vbrServer = function(numVMs, mode, offsite) {

		var result = {};

		result.offsite = false;
		result.copyJobs = 0;

		if (mode == "classic") {
				result.jobs = Math.ceil(numVMs / client.VMsPerJobClassic);
		}

		if (mode == "pervm") {
				result.jobs = Math.ceil(numVMs / client.VMsPerJobPerVMChain);
		}

		if (offsite == true) {
				result.copyJobs = result.jobs;
				result.offsite = true;
		}

		var concurrentJobs = result.jobs * 0.65;


		result.totalJobs = concurrentJobs + result.copyJobs;
		result.totalJobsCPU = Math.ceil(result.totalJobs / 10);

		result.CPU = architect.round(result.totalJobsCPU, 2);
		result.RAM = architect.round(result.CPU * 3, 4);

		return result;
};

/**
 * SQL Server database
 *
 * @param numVMs {number} Number of virtual machines
 * @param offsite {bool} Determine of backup copy jobs are enabled
 * @returns {object} Information about SQL Server
 */
architect.SQLDatabase = function(numVMs, offsite) {

		if (offsite == true) {
				numVMs = numVMs*1.5;
		}

		var databaseCorePerVM = 0.0045;

		if (numVMs > 1000) {
				var databaseCorePerVMCoefficient = 0.00125;
		} else {
				var databaseCorePerVMCoefficient = 0;
		}


		var result = {};

		result.CPU = architect.round( (numVMs * databaseCorePerVM) - ((numVMs-1000) * databaseCorePerVMCoefficient) , 2);

		result.RAM = result.CPU * 2;

		return result;
};