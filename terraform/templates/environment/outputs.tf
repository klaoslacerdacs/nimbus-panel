output "instance_id" {
  value       = oci_core_instance.server.id
  description = "OCID of the provisioned compute instance"
}

output "public_ip" {
  value       = oci_core_instance.server.public_ip
  description = "Public IP address assigned to the instance"
}

output "private_ip" {
  value       = oci_core_instance.server.private_ip
  description = "Private IP address of the instance"
}

output "instance_state" {
  value       = oci_core_instance.server.state
  description = "Lifecycle state of the instance"
}

output "availability_domain" {
  value       = oci_core_instance.server.availability_domain
  description = "Availability domain where the instance was placed"
}

output "compartment_id" {
  value       = local.compartment_id
  description = "Compartment OCID (created or existing)"
}

output "vcn_id" {
  value       = local.vcn_id
  description = "VCN OCID (created or existing)"
}

output "subnet_id" {
  value       = local.subnet_id
  description = "Subnet OCID (created or existing)"
}

output "nsg_id" {
  value       = local.nsg_id
  description = "NSG OCID attached to the instance"
}
